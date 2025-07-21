<?php
require_once (__DIR__.'/settings.php');

class CRest
{
    const VERSION = '1.36';
    const BATCH_COUNT    = 50; // count batch 1 query
    const TYPE_TRANSPORT = 'json'; // json or xml

    public static function installApp()
    {
        $result = [
            'rest_only' => true,
            'install' => false
        ];
        if($_REQUEST['event'] == 'ONAPPINSTALL' && !empty($_REQUEST['auth']))
        {
            $result['install'] = static::setAppSettings($_REQUEST['auth'], true);
        }
        elseif($_REQUEST['PLACEMENT'] == 'DEFAULT')
        {
            $result['rest_only'] = false;
            $result['install'] = static::setAppSettings(
                [
                    'access_token' => htmlspecialchars($_REQUEST['AUTH_ID']),
                    'expires_in' => htmlspecialchars($_REQUEST['AUTH_EXPIRES']),
                    'application_token' => htmlspecialchars($_REQUEST['APP_SID']),
                    'refresh_token' => htmlspecialchars($_REQUEST['REFRESH_ID']),
                    'domain' => htmlspecialchars($_REQUEST['DOMAIN']),
                    'client_endpoint' => 'https://' . htmlspecialchars($_REQUEST['DOMAIN']) . '/rest/',
                ],
                true
            );
        }

        static::setLog(
            [
                'request' => $_REQUEST,
                'result' => $result
            ],
            'installApp'
        );
        return $result;
    }

    protected static function callCurl($arParams)
    {
        if(!function_exists('curl_init'))
        {
            return [
                'error'             => 'error_php_lib_curl',
                'error_information' => 'need install curl lib'
            ];
        }
        $arSettings = static::getAppSettings();
        if($arSettings !== false)
        {
            if(isset($arParams['this_auth']) && $arParams['this_auth'] == 'Y')
            {
                $url = 'https://oauth.bitrix.info/oauth/token/';
            }
            else
            {
                $url = $arSettings["client_endpoint"] . $arParams['method'] . '.' . static::TYPE_TRANSPORT;
                if(empty($arSettings['is_web_hook']) || $arSettings['is_web_hook'] != 'Y')
                {
                    $arParams['params']['auth'] = $arSettings['access_token'];
                }
            }

            $sPostFields = http_build_query($arParams['params']);

            try
            {
                $obCurl = curl_init();
                curl_setopt($obCurl, CURLOPT_URL, $url);
                curl_setopt($obCurl, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($obCurl, CURLOPT_POSTREDIR, 10);
                curl_setopt($obCurl, CURLOPT_USERAGENT, 'Bitrix24 CRest PHP ' . static::VERSION);
                if($sPostFields)
                {
                    curl_setopt($obCurl, CURLOPT_POST, true);
                    curl_setopt($obCurl, CURLOPT_POSTFIELDS, $sPostFields);
                }
                curl_setopt(
                    $obCurl, CURLOPT_FOLLOWLOCATION, (isset($arParams['followlocation']))
                    ? $arParams['followlocation'] : 1
                );
                if(defined("C_REST_IGNORE_SSL") && C_REST_IGNORE_SSL === true)
                {
                    curl_setopt($obCurl, CURLOPT_SSL_VERIFYPEER, false);
                    curl_setopt($obCurl, CURLOPT_SSL_VERIFYHOST, false);
                }
                $out = curl_exec($obCurl);
                $info = curl_getinfo($obCurl);
                if(curl_errno($obCurl))
                {
                    $info['curl_error'] = curl_error($obCurl);
                }
                if(static::TYPE_TRANSPORT == 'xml' && (!isset($arParams['this_auth']) || $arParams['this_auth'] != 'Y'))
                {
                    $result = $out;
                }
                else
                {
                    $result = static::expandData($out);
                }
                curl_close($obCurl);

                if(!empty($result['error']))
                {
                    if($result['error'] == 'expired_token' && empty($arParams['this_auth']))
                    {
                        $result = static::GetNewAuth($arParams);
                    }
                    else
                    {
                        $arErrorInform = [
                            'expired_token'          => 'expired token, cant get new auth? Check access oauth server.',
                            'invalid_token'          => 'invalid token, need reinstall application',
                            'invalid_grant'          => 'invalid grant, check out define C_REST_CLIENT_SECRET or C_REST_CLIENT_ID',
                            'invalid_client'         => 'invalid client, check out define C_REST_CLIENT_SECRET or C_REST_CLIENT_ID',
                            'QUERY_LIMIT_EXCEEDED'   => 'Too many requests, maximum 2 query by second',
                            'ERROR_METHOD_NOT_FOUND' => 'Method not found! You can see the permissions of the application: CRest::call(\'scope\')',
                            'NO_AUTH_FOUND'          => 'Some setup error b24, check in table "b_module_to_module" event "OnRestCheckAuth"',
                            'INTERNAL_SERVER_ERROR'  => 'Server down, try later'
                        ];
                        if(!empty($arErrorInform[$result['error']]))
                        {
                            $result['error_information'] = $arErrorInform[$result['error']];
                        }
                    }
                }
                if(!empty($info['curl_error']))
                {
                    $result['error'] = 'curl_error';
                    $result['error_information'] = $info['curl_error'];
                }

                static::setLog(
                    [
                        'url'    => $url,
                        'info'   => $info,
                        'params' => $arParams,
                        'result' => $result
                    ],
                    'callCurl'
                );

                return $result;
            }
            catch(Exception $e)
            {
                static::setLog(
                    [
                        'message' => $e->getMessage(),
                        'code' => $e->getCode(),
                        'trace' => $e->getTrace(),
                        'params' => $arParams
                    ],
                    'exceptionCurl'
                );

                return [
                    'error' => 'exception',
                    'error_exception_code' => $e->getCode(),
                    'error_information' => $e->getMessage(),
                ];
            }
        }
        else
        {
            static::setLog(
                [
                    'params' => $arParams
                ],
                'emptySetting'
            );
        }

        return [
            'error'             => 'no_install_app',
            'error_information' => 'error install app, pls install local application '
        ];
    }

    public static function call($method, $params = [])
    {
        $arPost = [
            'method' => $method,
            'params' => $params
        ];
        if(defined('C_REST_CURRENT_ENCODING'))
        {
            $arPost['params'] = static::changeEncoding($arPost['params']);
        }

        $result = static::callCurl($arPost);
        return $result;
    }

    public static function callBatch($arData, $halt = 0)
    {
        $arResult = [];
        if(is_array($arData))
        {
            if(defined('C_REST_CURRENT_ENCODING'))
            {
                $arData = static::changeEncoding($arData);
            }
            $arDataRest = [];
            $i = 0;
            foreach($arData as $key => $data)
            {
                if(!empty($data['method']))
                {
                    $i++;
                    if(static::BATCH_COUNT >= $i)
                    {
                        $arDataRest['cmd'][$key] = $data['method'];
                        if(!empty($data['params']))
                        {
                            $arDataRest['cmd'][$key] .= '?' . http_build_query($data['params']);
                        }
                    }
                }
            }
            if(!empty($arDataRest))
            {
                $arDataRest['halt'] = $halt;
                $arPost = [
                    'method' => 'batch',
                    'params' => $arDataRest
                ];
                $arResult = static::callCurl($arPost);
            }
        }
        return $arResult;
    }

    private static function GetNewAuth($arParams)
    {
        $result = [];
        $arSettings = static::getAppSettings();
        if($arSettings !== false)
        {
            $arParamsAuth = [
                'this_auth' => 'Y',
                'params'    =>
                    [
                        'client_id'     => $arSettings['client_id'] ?? '',
                        'grant_type'    => 'refresh_token',
                        'client_secret' => $arSettings['client_secret'] ?? '',
                        'refresh_token' => $arSettings["refresh_token"] ?? '',
                    ]
            ];
            $newData = static::callCurl($arParamsAuth);
            if(isset($newData['client_id']))
            {
                unset($newData['client_id']);
            }
            if(isset($newData['client_secret']))
            {
                unset($newData['client_secret']);
            }
            if(isset($newData['error']))
            {
                unset($newData['error']);
            }
            if(static::setAppSettings($newData))
            {
                $arParams['this_auth'] = 'N';
                $result = static::callCurl($arParams);
            }
        }
        return $result;
    }

    private static function setAppSettings($arSettings, $isInstall = false)
    {
        $return = false;
        if(is_array($arSettings))
        {
            $oldData = static::getAppSettings();
            if($isInstall !== true && !empty($oldData) && is_array($oldData))
            {
                $arSettings = array_merge($oldData, $arSettings);
            }
            $return = static::setSettingData($arSettings);
        }
        return $return;
    }

    private static function getAppSettings()
    {
        if(defined("C_REST_WEB_HOOK_URL") && !empty(C_REST_WEB_HOOK_URL))
        {
            $arData = [
                'client_endpoint' => C_REST_WEB_HOOK_URL,
                'is_web_hook'     => 'Y'
            ];
            $isCurrData = true;
        }
        else
        {
            $arData = static::getSettingData();
            $isCurrData = false;
            if(
                !empty($arData['access_token']) &&
                !empty($arData['domain']) &&
                !empty($arData['refresh_token']) &&
                !empty($arData['application_token']) &&
                !empty($arData['client_endpoint'])
            )
            {
                $isCurrData = true;
            }
        }

        return ($isCurrData) ? $arData : false;
    }

    protected static function getSettingData()
    {
        $return = [];
        if(file_exists(__DIR__ . '/settings.json'))
        {
            $return = static::expandData(file_get_contents(__DIR__ . '/settings.json'));
            if(defined("C_REST_CLIENT_ID") && !empty(C_REST_CLIENT_ID))
            {
                $return['client_id'] = C_REST_CLIENT_ID;
            }
            if(defined("C_REST_CLIENT_SECRET") && !empty(C_REST_CLIENT_SECRET))
            {
                $return['client_secret'] = C_REST_CLIENT_SECRET;
            }
        }
        return $return;
    }

    protected static function changeEncoding($data, $encoding = true)
    {
        if (!function_exists('iconv')) {
            return $data;
        }

        if(is_array($data))
        {
            $result = [];
            foreach ($data as $k => $item)
            {
                $k = static::changeEncoding($k, $encoding);
                $result[$k] = static::changeEncoding($item, $encoding);
            }
            return $result;
        }
        else
        {
            if($encoding)
            {
                return iconv(C_REST_CURRENT_ENCODING, "UTF-8//TRANSLIT", $data);
            }
            else
            {
                return iconv("UTF-8", C_REST_CURRENT_ENCODING, $data);
            }
        }
    }

    protected static function wrapData($data, $debag = false)
    {
        if(defined('C_REST_CURRENT_ENCODING'))
        {
            $data = static::changeEncoding($data, true);
        }
        $return = json_encode($data, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);

        if($debag)
        {
            $e = json_last_error();
            if ($e != JSON_ERROR_NONE)
            {
                if ($e == JSON_ERROR_UTF8)
                {
                    return 'Failed encoding! Recommended \'UTF - 8\' or set define C_REST_CURRENT_ENCODING = current site encoding for function iconv()';
                }
            }
        }

        return $return;
    }

    protected static function expandData($data)
    {
        $return = json_decode($data, true);
        if(defined('C_REST_CURRENT_ENCODING'))
        {
            $return = static::changeEncoding($return, false);
        }
        return $return;
    }

    protected static function setSettingData($arSettings)
    {
        return  (boolean)file_put_contents(__DIR__ . '/settings.json', static::wrapData($arSettings));
    }

    public static function setLog($arData, $type = '')
    {
        $return = false;
        if(!defined("C_REST_BLOCK_LOG") || C_REST_BLOCK_LOG !== true)
        {
            if(defined("C_REST_LOGS_DIR"))
            {
                $path = C_REST_LOGS_DIR;
            }
            else
            {
                $path = __DIR__ . '/logs/';
            }
            if(!file_exists($path))
            {
                mkdir($path, 0777, true);
            }
            $fileName = $path . date('Y-m-d') . ($type ? '_' . $type : '') . '.log';
            $content = '';

            if(defined("C_REST_LOG_TYPE_DUMP") && C_REST_LOG_TYPE_DUMP === true)
            {
                $content .= var_export($arData, true);
            }
            else
            {
                $content .= print_r($arData, true);
            }
            $content .= PHP_EOL . '----------------------------------------' . PHP_EOL;

            $return = (boolean)file_put_contents($fileName, $content, FILE_APPEND);
        }
        return $return;
    }
}
