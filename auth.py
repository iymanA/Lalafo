from fastapi import FastAPI, WebSocket, WebSocketDisconnect, HTTPException, Path
from pydantic import BaseModel
import uvicorn
import sqlite3
import bcrypt
import jwt
import threading
import asyncio
import time
import websockets
from bitrix24 import Bitrix24
from selenium import webdriver
from selenium.webdriver.common.by import By
from selenium.webdriver.common.keys import Keys
from selenium.webdriver.chrome.options import Options
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC
from dotenv import load_dotenv
import os

# === CONFIG ===
load_dotenv()
SECRET_KEY = os.getenv("SECRET_KEY", "secret")
ALGORITHM = "HS256"
BITRIX_WEBHOOK_URL = os.getenv("BITRIX_WEBHOOK_URL", "https://your-domain.bitrix24.ru/rest/1/your-webhook-code/")
BITRIX_DOMAIN = os.getenv("BITRIX_DOMAIN", "your-domain.bitrix24.ru")

app = FastAPI()
clients = []
bx = Bitrix24(BITRIX_WEBHOOK_URL)

# === Global State ===
driver_ref = {
    "driver": None,
    "input_box": None,
    "messages_container": None,
    "seen_messages": set(),
    "last_sent": []
}

messages_by_chat = {}
listening = False
current_chat_number = None

# === DB ===
def init_db():
    conn = sqlite3.connect("users.db")
    c = conn.cursor()
    c.execute("""
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password BLOB NOT NULL
        )
    """)
    conn.commit()
    conn.close()

# === MODELS ===
class User(BaseModel):
    username: str
    password: str

class SessionData(BaseModel):
    username: str
    password: str

class MessageData(BaseModel):
    chat_number: str
    message: str

# === AUTH ===
@app.post("/register")
def register(user: User):
    conn = sqlite3.connect("users.db")
    c = conn.cursor()
    hashed_password = bcrypt.hashpw(user.password.encode(), bcrypt.gensalt())
    try:
        c.execute("INSERT INTO users (username, password) VALUES (?, ?)", (user.username, hashed_password))
        conn.commit()
        return {"message": "User registered successfully"}
    except sqlite3.IntegrityError:
        raise HTTPException(status_code=400, detail="Username already exists")
    finally:
        conn.close()

@app.post("/login")
def login(user: User):
    conn = sqlite3.connect("users.db")
    c = conn.cursor()
    c.execute("SELECT password FROM users WHERE username = ?", (user.username,))
    row = c.fetchone()
    conn.close()
    if not row or not bcrypt.checkpw(user.password.encode(), row[0]):
        raise HTTPException(status_code=401, detail="Invalid credentials")
    token = jwt.encode({"username": user.username}, SECRET_KEY, algorithm=ALGORITHM)
    return {"token": token}

# === WEBSOCKET ===
@app.websocket("/ws")
async def websocket_endpoint(websocket: WebSocket):
    await websocket.accept()
    clients.append(websocket)
    try:
        while True:
            data = await websocket.receive_text()
            for client in clients:
                await client.send_text(f"\U0001F4E9 {data}")
    except WebSocketDisconnect:
        clients.remove(websocket)

async def send_message_to_ws(message: str):
    uri = "ws://127.0.0.1:8000/ws"
    async with websockets.connect(uri) as websocket:
        await websocket.send(message)

# === LALAFO ===
@app.get("/messages/{chat_number}")
def get_messages(chat_number: int = Path(..., description="Номер чата")):
    msgs = messages_by_chat.get(chat_number, [])
    formatted = [f"Я: {m['text']}" if m['from'] == 'me' else f"Он: {m['text']}" for m in msgs]
    return {"chat_number": chat_number, "messages": formatted}

@app.post("/send")
def send_message(data: MessageData):
    global listening, current_chat_number
    chat_num = int(data.chat_number)

    if not driver_ref["driver"]:
        return {"status": "error", "error": "driver not initialized"}

    try:
        chat_elements = WebDriverWait(driver_ref["driver"], 10).until(
            EC.presence_of_all_elements_located((By.XPATH, '//div[contains(@class,"chat-list")]/div'))
        )

        if current_chat_number != chat_num:
            current_chat_number = chat_num
            chat_elements[chat_num - 1].click()
            driver_ref["seen_messages"].clear()

        messages_container = WebDriverWait(driver_ref["driver"], 10).until(
            EC.presence_of_element_located((By.ID, "scrollableDiv"))
        )

        input_box = WebDriverWait(driver_ref["driver"], 10).until(
            EC.presence_of_element_located((By.XPATH,
                '//*[@id="__next"]/div/div[1]/div/section/div/div/div[2]/div[2]/div/div[3]/div[2]/div[2]/div/div[1]/textarea'))
        )

        input_box.send_keys(data.message)
        input_box.send_keys(Keys.ENTER)

        driver_ref["input_box"] = input_box
        driver_ref["messages_container"] = messages_container

        messages_by_chat.setdefault(chat_num, []).append({"from": "me", "text": data.message})
        driver_ref["last_sent"].append(data.message)

        def listen_loop():
            while True:
                try:
                    messages = driver_ref["messages_container"].find_elements(By.XPATH, "./div/div/div")
                    for msg in messages:
                        text = msg.text.strip()
                        if text and text not in driver_ref["seen_messages"]:
                            driver_ref["seen_messages"].add(text)
                            if text in driver_ref["last_sent"]:
                                driver_ref["last_sent"].remove(text)
                                continue
                            messages_by_chat.setdefault(current_chat_number, []).append({"from": "client", "text": text})
                            print(f"\n\U0001F4E5 {text}")
                            asyncio.run(send_message_to_ws(text))
                    driver_ref["driver"].execute_script("arguments[0].scrollTop = arguments[0].scrollHeight", driver_ref["messages_container"])
                    time.sleep(2)
                except Exception as e:
                    print(f"Listen error: {e}")
                    break

        if not listening:
            listening = True
            threading.Thread(target=listen_loop, daemon=True).start()

        return {"status": "ok", "message": f"Я: {data.message}"}
    except Exception as e:
        return {"status": "error", "error": str(e)}

@app.post("/start")
def start_session(data: SessionData):
    def run():
        selenium_login_only(data.username, data.password)
    threading.Thread(target=run, daemon=True).start()
    return {"status": "started"}

def selenium_login_only(username, password):
    options = Options()
    options.add_argument("--disable-notifications")
    driver = webdriver.Chrome(options=options)
    driver.get("https://lalafo.kg/")

    try:
        WebDriverWait(driver, 30).until(
            EC.element_to_be_clickable((By.XPATH, '//p[contains(@class, "guest-menu")]'))
        ).click()

        WebDriverWait(driver, 30).until(
            EC.element_to_be_clickable((By.XPATH, '//span[contains(text(), "Вход")]'))
        ).click()

        WebDriverWait(driver, 30).until(
            EC.presence_of_element_located((By.XPATH,
                '//*[@id="modal"]/div/div/div/div/div/div/div/div[2]/form/div[1]/div/div[1]/input'))
        ).send_keys(username)

        WebDriverWait(driver, 30).until(
            EC.presence_of_element_located((By.XPATH,
                '//*[@id="modal"]/div/div/div/div/div/div/div/div[2]/form/div[2]/div/div/input'))
        ).send_keys(password)

        WebDriverWait(driver, 30).until(
            EC.element_to_be_clickable((By.XPATH, '//button[contains(text(), "Войти")]'))
        ).click()

        WebDriverWait(driver, 30).until(
            EC.presence_of_element_located((By.XPATH, '//a[contains(@href, "/account/chats")]'))
        )
        driver.get("https://lalafo.kg/account/chats")

        driver_ref["driver"] = driver
        print("\U0001F512 Успешный вход и переход к чатам.")
    except Exception as e:
        print("\u274C Ошибка входа:", e)

# === BITRIX24 ===
@app.post("/bitrix/message")
async def receive_bitrix_message(data: MessageData):
    messages_by_chat.setdefault(int(data.chat_number), []).append({"from": "me", "text": data.message})
    await send_message_to_ws(f"Bitrix24 [{data.chat_number}]: {data.message}")
    return {"status": "ok", "message": f"Received from PHP: {data.message}"}

@app.get("/bitrix/chats")
async def receive_bitrix_chats(data: dict):
    return {"status": "ok", "chats": data['chats']}

@app.post("/bitrix/webhook")
async def bitrix_webhook(data: dict):
    if data.get('event') == 'ONIMMESSAGEADD':
        chat_id = data['data']['CHAT_ID']
        message = data['data']['MESSAGE']
        messages_by_chat.setdefault(int(chat_id), []).append({"from": "client", "text": message})
        await send_message_to_ws(f"Bitrix24 [{chat_id}]: {message}")
    return {"status": "ok"}

# === Запуск ===
if __name__ == "__main__":
    init_db()
    uvicorn.run(app, host="127.0.0.1", port=8000)