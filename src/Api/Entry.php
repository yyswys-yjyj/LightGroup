<?php
declare(strict_types=1);

namespace LightGroup\Api;

use LightGroup\Http\Json;
use LightGroup\Store\Db;
use LightGroup\Web\Site;
use LightGroup\Web\Url;
use LightGroup\Web\View;

/**
 * Operit 兼容层的入口。
 *
 * 协议把 base 写死成 https://host/g/，后面直接拼 api/me，所以 /g/ 这个形状
 * 改不了。这里只负责把 /g/xxx 拆成 {slug, path} 两段，剩下的交给 Compat。
 *
 * 没有单独的 public/g/index.php：全站只有一个 public/index.php，
 * 这样路由上永远不会出现任何一个 .php。
 */
final class Entry
{
    public static function handle(): void
    {
        lg_session();

        if (!lg_installed()) {
            Json::fail("站点尚未安装", 404);
        }

        $rest = self::rest();
        $slug = "";
        $path = $rest;
        $parts = explode("/", $rest, 2);
        if (count($parts) === 2 && $parts[0] !== "" && $parts[0] !== "join" && $parts[0] !== "api") {
            $slug = $parts[0];
            $path = $parts[1];
        }

        // /g/ 与 /g/{slug}/ 是网页入口，老书签照样能开
        if ($rest === "" || (substr_count($rest, "/") === 0 && $rest !== "join")) {
            $site = Site::info();
            $basePath = "/g/" . ($slug !== "" ? $slug . "/" : "");
            $pdo = Db::connect();
            $me = Session::member($pdo);
            if ($me === null) {
                header("Location: " . Url::to("/login?next=" . rawurlencode($basePath)), true, 302);
                exit;
            }
            $scope = Compat::scopeBySlugPublic($pdo, $slug);
            if ($scope === null) {
                View::render("notfound", ["site" => $site, "path" => $basePath], 404);
                exit;
            }
            $bootMe = null;
            if ((int) $me["scope_id"] === (int) $scope["id"]) {
                $bootMe = ["id" => (int) $me["id"], "name" => $me["name"], "kind" => $me["kind"]];
            }
            View::render("app-shell", ["site" => $site, "base" => $basePath, "bootMe" => $bootMe]);
            exit;
        }

        if (Profile::handle($slug, $path)) return;
        Compat::handle($slug, $path);
    }

    /** /g/ 后面那一段，去掉首尾斜杠 */
    private static function rest(): string
    {
        $uri = rawurldecode((string) parse_url($_SERVER["REQUEST_URI"] ?? "/", PHP_URL_PATH));
        if ($uri === "/g" || $uri === "/g/") {
            return "";
        }
        $pos = strpos($uri, "/g/");
        return $pos === false ? "" : trim(substr($uri, $pos + 3), "/");
    }
}
