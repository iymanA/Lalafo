<?php
require_once (__DIR__.'/crest.php');

$result = CRest::installApp();

if($result['rest_only'] === false): ?>
    <head>
        <script src="//api.bitrix24.com/api/v1/"></script>
        <?php if($result['install'] == true): ?>
            <script>
                BX24.init(function(){
                    BX24.installFinish();
                });
            </script>
        <?php endif; ?>
    </head>
    <body>
        <?php if($result['install'] == true): ?>
            Установка завершена успешно!
        <?php else: ?>
            Ошибка при установке.
        <?php endif; ?>
    </body>
<?php endif; ?>
