<?php
declare(strict_types=1);

namespace LightGroup\Web;

use LightGroup\Http\Json;
use LightGroup\Setup\Installer;

final class Setup
{
    public static function handle(): void
    {
        if (lg_installed()) {
            View::render('notfound', ['site' => Site::info(), 'path' => '/setup'], 404);
            return;
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $body = json_decode((string) file_get_contents('php://input'), true);
            if (!is_array($body)) $body = $_POST;
            if ((string) ($body['action'] ?? 'install') === 'test-db') {
                Json::out(Installer::testDb($body));
                return;
            }
            // 装之前也能拉 well-known：只读外网文档，不碰数据库
            if ((string) ($body['action'] ?? 'install') === 'oauth-discover') {
                Json::out(\LightGroup\Api\OAuth::discover((string) ($body['issuer'] ?? '')));
                return;
            }
            Json::out(Installer::install($body));
            return;
        }
        $checks = Installer::checks();
        $ok = true;
        foreach ($checks as $c) if (!$c['ok']) $ok = false;
        View::render('setup', ['site' => Site::info(), 'checks' => $checks, 'ready' => $ok]);
    }
}
