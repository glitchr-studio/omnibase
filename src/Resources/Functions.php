<?php

namespace {

    use Base\BaseBundle;

    function dumpif(...$variadic)
    {
        BaseBundle::dump("", ...$variadic);
    }

    function enable_dumpif()
    {
        BaseBundle::enableDump("");
    }

    function disable_dumpif()
    {
        BaseBundle::disableDump("");
    }

    if (!extension_loaded('bcmath')) {
        throw new RuntimeException("bcmath is not installed");
    }

    function extract_instanceof(string $type, &...$args): array
    {
        $instances = [];
        foreach ($args as $key => &$arg) {
            if ($arg instanceof $type) {
                $instances[] = $arg;
                unset($arg);
            }
        }

        return $instances;
    }

    function strip_duplicates($input) {
        
        // Split pascal case
        preg_match_all('/[A-Z][a-z]+/', $input, $matches);
        $tokens = $matches[0];

        $uniqueTokens = array_unique($tokens);
        return implode('', $uniqueTokens);
    }
    
    /**
     * Get the topmost parent class in an inheritance hierarchy
     *
     * Traverses the inheritance chain to find the root ancestor class.
     *
     * @param object|string $object_or_class Object instance or class name
     * @return string|false The root class name, or false if invalid input
     */
    function get_root_class(object|string $object_or_class): string|false
    {
        $class = is_object($object_or_class) ? get_class($object_or_class) : $object_or_class;
        if (!$class) {
            return false;
        }

        while (get_parent_class($class)) {
            $class = get_parent_class($class);
        }

        return $class;
    }

    function sign(int|float $n): string
    {
        return ($n < 0) ? "-" : "+";
    }

    function rrmdir($dir) { 
        
        if (is_dir($dir)) { 
            $objects = scandir($dir);
            foreach ($objects as $object) { 

                if ($object != "." && $object != "..") { 

                    if (is_dir($dir. DIRECTORY_SEPARATOR .$object) && !is_link($dir."/".$object))
                        rrmdir($dir. DIRECTORY_SEPARATOR .$object);
                else
                    unlink($dir. DIRECTORY_SEPARATOR .$object); 
                } 
            }
            rmdir($dir); 
        } 
    }

    function is_hex(string $str): bool {
        if (str_starts_with(strtolower($str), '0x')) {
            $str = substr($str, 2);
        }

        return ctype_xdigit($str);
    }


    /**
     * @param mixed $stringOrObject
     * @return bool
     */
    function is_json(mixed $stringOrObject)
    {        
        if (is_string($stringOrObject)) {
            
            $isDict = str_contains($stringOrObject, "{") && str_contains($stringOrObject, "}");
            $isArray = str_contains($stringOrObject, "[") && str_contains($stringOrObject, "]");
            if($isDict || $isArray) {
                json_decode($stringOrObject);
                return json_last_error() === JSON_ERROR_NONE;
            }
        }

        if (is_object($stringOrObject)) {
            return (bool)get_object_vars($stringOrObject);
        }

        return false;
    }

    function json_leaves(mixed &$json): array
    {
        $leaves = [];

        $array = &$json;
        if(!is_array($json)) {
            $array = json_decode(is_object($json) ? json_encode($json) : $json, true);
        }

        array_walk_recursive($array, function(&$value, $key) use(&$leaves) {
            $leaves[] = &$value;
        });

        return $leaves;
    }

    const MAX_DIRSIZE = 255;
    /**
     * @param $path
     * @param int $subdivision
     * @param int|array $length
     * @return string|null
     */
    function path_subdivide($path, int $subdivision, int|array $length = 1)
    {
        $dirname = dirname($path);
        $basename = basename($path);

        $last = strlen($basename);
        $length = array_pad(is_array($length) ? $length : [], $subdivision, is_array($length) ? 1 : $length);

        $remainingSubdivision = ceil(($last - array_sum($length)) / MAX_DIRSIZE);
        $length = array_pad($length, $subdivision + $remainingSubdivision, MAX_DIRSIZE);

        $subPath = $dirname . "/";
        $last = strlen($basename);

        for ($i = 0, $cursor = 0, $N = count($length); $i < $N; $i++) {
            if ($cursor > $last) {
                break;
            }
            $subPath .= substr($basename, $cursor, $length[$i]) . "/";
            $cursor += $length[$i];
        }

        $subPath .= substr($basename, $cursor);
        return str_strip($subPath, "./", "/");
    }

    /**
     * @param string $str
     * @return array|string|string[]|null
     */
    function str_strip_nonprintable(string $str)
    {
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F-\x9F\xA0]/u', "", $str);
    }

    /**
     * @param string $str
     * @return array|string|string[]|null
     */
    function str_strip_chars(string $str)
    {
        return preg_replace("/[a-zA-Z]/", "", $str);
    }

    /**
     * @param string $str
     * @return array|string|string[]|null
     */
    function str_strip_numbers(string $str)
    {
        return preg_replace("/[^a-zA-Z]/", "", $str);
    }

    /**
     * @param string $str
     * @return array|string|string[]|null
     */
    function str_strip_specials(string $str)
    {
        return preg_replace("/[^a-zA-Z0-9]/", "", $str);
    }

    function format_uuid(string $uuid, bool $strict = false): string|false
    {
        $uuid = str_strip_specials($uuid);

        $pattern = $strict ?  "/^[a-f0-9]{32}$/i" :  "/[a-f0-9]{32}/i";
        if (!preg_match($pattern, $uuid)) {
            return false;
        }

        return
            substr($uuid, 0, 8) . "-" .
            substr($uuid, 8, 4) . "-" .
            substr($uuid, 12, 4) . "-" .
            substr($uuid, 16, 4) . "-" .
            substr($uuid, 20, 12);
    }

    function typeof(mixed $input): string
    {
        return gettype($input);
    }

    /**
     * @param $input
     * @return array|string|string[]|null
     */
    function interpret_link($input)
    {
        return preg_replace_callback(
            "@
        (?:http|ftp|https)://
        (?:
            (?P<domain>\S+?) (?:/\S+)|
            (?P<domain_only>\S+)
        )
        @sx",
            function ($a) {
                $link = "<a href='" . $a[0] . "'>";
                $link .= $a["domain"] !== "" ? $a["domain"] : $a["domain_only"];
                $link .= "</a>";
                return $link;
            },
            $input
        );
    }

    // Value (1) => 0 degrees: the correct orientation, no adjustment is required.
    // Value (2) => 0 degrees, mirrored: image has been flipped back-to-front.
    // Value (3) => 180 degrees: image is upside down.
    // Value (4) => 180 degrees, mirrored: image has been flipped back-to-front and is upside down.
    // Value (5) => 90 degrees: image has been flipped back-to-front and is on its side.
    // Value (6) => 90 degrees, mirrored: image is on its side.
    // Value (7) => 270 degrees: image has been flipped back-to-front and is on its fa-regular side.
    // Value (8) => 270 degrees, mirrored: image is on its fa-regular side.
    function getimageorientation(string $fname): int
    {
        try {
            $exif = exif_read_data($fname);
        } catch (ErrorException $e) {
            return 1;
        }

        return $exif["Orientation"] ?? 1;
    }

    function imagedimswap(string $fname): bool
    {
        return getimageorientation($fname) > 4;
    }

    function unsetcookies(string $domain = '')
    {
        foreach ($_COOKIE as $name => $value)
        {
            unsetcookie($name, $domain);
        }
    }

    function unsetcookie(string $name, string $domain = '')
    {
        setcookie($name, '', 1, '', $domain);
        setcookie($name, '', 1, '/', $domain);

	if(!$domain) {

                $domain = parse_url2(get_url())["domain"] ?? '';
                if($domain) {

                        setcookie($name, '', 1, '', '.'.$domain);
                        setcookie($name, '', 1, '/', '.'.$domain);
                }
        }
    }

    /**
     * @param $image
     * @param $fname
     * @return void
     */
    function image_fix_orientation(&$image, $fname)
    {
        $exif = exif_read_data($fname);

        if (!empty($exif['Orientation'])) {
            switch ($exif['Orientation']) {
                case 3:
                    $image = imagerotate($image, 180, 0);
                    break;

                case 6:
                    $image = imagerotate($image, 90, 0);
                    break;

                case 8:
                    $image = imagerotate($image, -90, 0);
                    break;
            }
        }
    }

    /**
     * A symbolic link put in place in one step, safe when several processes do
     * it at once (the web container and the worker both clearing the cache as
     * they start): the link is made beside its place under a name of its own,
     * then renamed over it - rename() replaces a link atomically, where
     * unlink() + symlink() let the second process fail on "File exists" (or on
     * a link that just went). An existing link to the same target is left as it is.
     *
     * @return bool false when the place is taken by something that is not a link (a directory, a file)
     */
    function symlink_atomic(string $target, string $link): bool
    {
        // One look at what is there (lstat: the link itself, not what it points to). Asked in two
        // steps - is_link(), then file_exists() - a link another process put in between the two
        // was taken for a directory in its place.
        clearstatcache(true, $link);
        $stat = @lstat($link);
        if (false !== $stat) {
            if (0120000 !== ($stat['mode'] & 0170000)) {
                return false;
            }
            if (@readlink($link) === $target) {
                return true;
            }
        }

        $temporary = $link . "." . bin2hex(random_bytes(6)) . ".tmp";
        if (!@symlink($target, $temporary)) {
            return is_link($link) && @readlink($link) === $target;
        }

        if (!@rename($temporary, $link)) {
            @unlink($temporary);

            return is_link($link) && @readlink($link) === $target;
        }

        return true;
    }

    /**
     * @return float|int
     */
    function benchmark_start()
    {
        $_SERVER["APP_TIMER"] = microtime(true);
    }

    function benchmark_lap() // ms
    {
        if (!array_key_exists("APP_TIMER", $_SERVER)) {
            benchmark_start();
        }

        return 1000 * (microtime(true) - $_SERVER["APP_TIMER"]);
    }

    function benchmark($memory_footprint = true, $real_usage = true)
    {
        $lap_time = benchmark_lap();
        $s = sprintf("⏱️  Lap: %.2f ms ", $lap_time);

        if ($memory_footprint) {

            $memory_usage = format_bytes(memory_get_usage(true));
            $memory_peak  = format_bytes(memory_get_peak_usage(true));

            $s .= sprintf("| 💾 Memory: %.2f MB | 📈 Peak: %.2f MB", $memory_usage, $memory_peak);
        }

        return $s;
    }
    
    function print_benchmark(): void
    {
        echo benchmark() . PHP_EOL;
    }

    function format_bytes($bytes, $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $bytes = max($bytes, 0);
        $pow   = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow   = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);

        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    function get_url(?string $scheme = null, ?string $http_host = null, ?string $request_uri = null): ?string
    {
        $http_host = $http_host ? $http_host : null;
        $scheme = $scheme ? $scheme : null;
        $request_uri = $request_uri ? $request_uri : null;

        // Honor an explicitly-passed $scheme; only derive it from $_SERVER when
        // the caller didn't give one. The old code unconditionally overwrote
        // $scheme on the next line, so passing "http"/"https" (as
        // AdvancedUrlGenerator and RouterSubscriber do) had no effect.
        if ($scheme === null) {
            $scheme = $_SERVER['HTTPS'] ?? $_SERVER["USE_HTTPS"] ?? $_SERVER['REQUEST_SCHEME'] ?? $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? null;
        }
        $scheme = $scheme && (strcasecmp('on', $scheme) == 0 || strcasecmp('https', $scheme) == 0);
        $scheme = $scheme ? "https" : "http";

        $domain = explode(":", $http_host ?? $_SERVER["HTTP_HOST"] ?? "")[0] ?? $_SERVER["SERVER_NAME"] ?? null;
        $port = explode(":", $http_host ?? $_SERVER["HTTP_HOST"] ?? "")[1] ?? $_SERVER["SERVER_PORT"] ?? null;
        $port = $port != 80 && $port != 443 ? $port : null;

        $request_uri ??= $_SERVER["REQUEST_URI"] ?? null; // Fragment is contained in request URI
	
        return compose_url($scheme, null, null, null, null, $domain, $port, $request_uri == "/" ? null : $request_uri);
    }
    
    /**
     * @param string $dir
     * @return bool
     */
    function delete_directory(string $dir)
    {
        if (!file_exists($dir)) {
            return true;
        }

        if (!is_dir($dir)) {
            return unlink($dir);
        }

        foreach (scandir($dir) as $item) {
            if ($item == '.' || $item == '..') {
                continue;
            }
            if (!delete_directory($dir . DIRECTORY_SEPARATOR . $item)) {
                return false;
            }
        }

        return rmdir($dir);
    }

    const SANITIZE_URL_STANDARD = 0;
    const SANITIZE_URL_NOMACHINE = 1;
    const SANITIZE_URL_NOSUBDOMAIN = 2;
    const SANITIZE_URL_KEEPSLASH = 4;
    /**
     * @param string $url
     * @param int $format
     * @return string|null
     */
    function sanitize_url(string $url, int $format = SANITIZE_URL_STANDARD)
    {
        return format_url($url, $format);
    }

    const FORMAT_URL_STANDARD = SANITIZE_URL_STANDARD;
    const FORMAT_URL_NOMACHINE = SANITIZE_URL_NOMACHINE;
    const FORMAT_URL_NOSUBDOMAIN = SANITIZE_URL_NOSUBDOMAIN;
    const FORMAT_URL_KEEPSLASH = SANITIZE_URL_KEEPSLASH;
    function format_url(string $url, int $format = FORMAT_URL_STANDARD): ?string
    {
        $parse = parse_url2($url);
        if ($parse === false) {
            return null;
        }

        if ($format & FORMAT_URL_NOMACHINE) {
            $parse = array_key_removes($parse, "machine");
        }
        if ($format & FORMAT_URL_NOSUBDOMAIN) {
            $parse = array_key_removes($parse, "subdomain");
        }

        $parse["path"] = str_rstrip($parse["path"] ?? "", "/");
        $parse["path"] = $parse["base_dir"].$parse["path"];

        return compose_url(
            $parse["scheme"] ?? null,
            $parse["user"] ?? null,
            $parse["password"] ?? null,
            $parse["machine"] ?? null,
            $parse["subdomain"] ?? null,
            $parse["domain"] ?? null,
            $parse["port"] ?? null,
            ($format & FORMAT_URL_KEEPSLASH) ? $parse["path"] : str_replace("//", "/", $parse["path"]),
            $parse["query"] ?? null,
            $parse["fragment"] ?? null
        );
    }

    function compose_url(
        string|array|null $scheme = null,
        ?string           $user = null,
        ?string           $password = null,
        ?string           $machine = null,
        ?string           $subdomain = null,
        ?string           $domain = null,
        string|int|null   $port = null,
        ?string           $path = null,
        ?string           $query = null,
        ?string           $fragment = null
    ): string {

        if (is_array($scheme)) {
            $parts = $scheme;
            return compose_url(
                $parts["scheme"] ?? null,
                $parts["user"] ?? null,
                $parts["password"] ?? null,
                $parts["machine"] ?? null,
                $parts["subdomain"] ?? null,
                $parts["domain"] ?? null,
                $parts["port"] ?? null,
                $parts["path"] ?? null,
                $parts["query"] ?? null,
                $parts["fragment"] ?? null
            );
        }

        // Default scheme according to Symfony semantics
        if ($domain && !$scheme) {
            $scheme = boolval($_SERVER["HTTPS"] ?? false) ? 'https' : 'http';
        }

        // Only build scheme if domain is provided
        $schemePart = ($domain && $scheme) ? $scheme . "://" : '';

        // User:password@ part
        $userPart = '';
        if ($domain && $user) {
            $userPart = $user;
            if ($password) {
                $userPart .= ':' . $password;
            }
            $userPart .= '@';
        }

        // Machine and subdomain
        $machinePart = ($domain && $machine) ? $machine . '.' : '';
        $subdomainPart = ($domain && $subdomain) ? $subdomain . '.' : '';

        // Port
        $portPart = ($domain && $port && !in_array($port, [80, 443])) ? ':' . $port : '';

        // Split path and query if path contains ?
        $queryInPath = null;
        if ($path) {
            $parts = explode('?', $path, 2);
            $path = $parts[0];
            if (isset($parts[1])) {
                $queryInPath = $parts[1];
            }
        }

        // Merge queries
        if ($queryInPath) {
            $query = $query ? $queryInPath . '&' . $query : $queryInPath;
        }
        $queryPart = $query ? '?' . $query : '';

        // Normalize path
        $path = rtrim($path ?? '', '/'); // remove trailing slash
        $pathPart = $path !== '' ? $path : '';
        $pathToQuerySlash = ($pathPart !== '' && $queryPart !== '') ? '/' : '';

        // Normalize fragment
        if ($fragment && !str_starts_with($fragment, '#')) {
            $fragment = '#' . $fragment;
        }
        $fragmentPart = $fragment ?? '';

        // Compose full URL
        $url = $schemePart
            . $userPart  // already contains "user:password@" if password present
            . $machinePart
            . $subdomainPart
            . $domain
            . $portPart
            . $pathPart
            . $pathToQuerySlash
            . $queryPart
            . $fragmentPart;

        return $url ?: '/';
    }

    function strtobool(string|bool $val): bool {
        if (is_bool($val)) return $val;
        return match(strtolower($val)) {
            'enable', 'enabled', 'on', 'true', '1', 'yes' => true,
            'disable', 'disabled', 'off', 'false', '0', 'no' => false,
            default => false,
        };
    }

    // NB: Path variable should not be removed, at most empty string..
    function parse_url2(?string $url, int $component = -1, string $base = "/"): array|string|int|false|null
    {
        if ($url === null) return null;
        
        $noscheme = !str_contains($url, "://");
        if ($noscheme) {
            $url = "file://" . $url;
        }

        $parse = parse_url($url, $component);
        if ($parse === false) {
            return false;
        }
        foreach ($parse as &$_) {
            $_ = str_lstrip($_, "file://");
        }

        if ($noscheme) {
            unset($parse["scheme"]);
        }

        $path = str_rstrip($parse['path'] ?? "", "/");
        $tail = str_strip($url, ["file://", $base], "/");
        $root = str_strip($url, ["file://", $base], [$tail, "/"]);
        if (!empty($root)) {
            $parse["root"] = $root;
        }

        if (array_key_exists("host", $parse)) {

            $port = array_key_exists("port", $parse) ? ":" . $parse["port"] : "";
            $parse["host"] = $parse["host"] . $port;

            //
            // Check if IP address provided
            if (filter_var($parse["host"], FILTER_VALIDATE_IP)) {
                $parse["ip"] = $parse["host"];
                $parse["domain"] = $parse["host"];
            }
            if (filter_var($parse["host"], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $parse["ipv4"] = $parse["host"];
            }
            if (filter_var($parse["host"], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                $parse["ipv6"] = $parse["host"];
            }

            //
            // Check if hostname
            if (preg_match('/([a-z0-9][a-z0-9\-]{0,63}\.[a-z]{2,6}(?:\.[a-z]{1,2})?)\:?([0-9]{1,5})?$/i', strtolower($parse["host"] ?? ""), $match)) {
                $hostWithoutPort = explode(":", $parse["host"])[0];
                $parse["fqdn"] = $hostWithoutPort . ".";

                if (count($match) > 1) {
                    $parse["domain"] = $match[1];
                }
                if (count($match) > 2) {
                    $parse["port"] = intval($match[2]);
                }

                $subdomain = str_rstrip($hostWithoutPort, "." . $parse["domain"]);
                if ($parse["domain"] !== $subdomain) {
                    $parse["subdomain"] = $subdomain;
                }

                if (array_key_exists("subdomain", $parse)) {
                    $list = explode(".", $parse["subdomain"]);
                    $parse["subdomain"] = array_pop($list);

                    if (!empty($list)) {
                        $parse["machine"] = implode(".", $list);
                    }
                }

                $domain = explode(".", $match[1]);
                $parse["sld"] = first($domain);
                $parse["tld"] = implode(".", tail($domain));
            
            } elseif (preg_match('/^([a-z0-9][a-z0-9\-]{0,63}?)\:?([0-9]{1,5})?$/i', strtolower($parse["host"] ?? ""), $match)) {
            
                if (count($match) > 1) {
                    $parse["domain"] = $match[1];
                }
                if (count($match) > 2) {
                    $parse["port"] = intval($match[2]);
                }
            }

            $parse["host"] = explode(":", $parse["host"] ?? "")[0];
        }

        $parse["path"] = $root . $path;
        if (array_key_exists("root", $parse)) {
            $parse["url"] = $root . $path;
        }
        if (!array_key_exists("base_dir", $parse)) {
            $parse["base_dir"] = $base;
        }
        if (array_key_exists("ip", $parse)) {
            array_key_removes($parse, "subdomain", "machine");
        }

        return $parse;
    }

    function is_instanceof(mixed $object_or_class, string|array $instanceOf): bool
    {
        // At least one class detection
        if (is_array($instanceOf)) {
            foreach ($instanceOf as $_instanceOf) {
                if (is_instanceof($object_or_class, $_instanceOf)) {
                    return true;
                }
            }

            return false;
        }

        // Default one
        if (interface_exists($instanceOf)) {
            return class_implements_interface($object_or_class, $instanceOf);
        }
        if (!class_exists($instanceOf)) {
            throw new Exception("Class \"$instanceOf\" doesn't exists.");
        }

        return is_a($object_or_class, $instanceOf, !is_object($object_or_class));
    }

    function is_abstract(mixed $object_or_class): bool
    {
        if (!class_exists($object_or_class)) {
            throw new Exception("Class \"$object_or_class\" doesn't exists.");
        }

        $class = new ReflectionClass($object_or_class);
        return $class->isAbstract();
    }

    function shrinkhex(?string $_hex): ?string
    {
        $hex = str_lstrip($_hex, "#");
        if (!$hex) {
            return null;
        }

        switch (strlen($hex)) {
            case 3:
                break;

            case 4:
                if (str_ends_with($hex, "F")) {
                    $hex = substr($hex, 0, 3);
                }
                break;

            case 6:
                if ($hex[0] == $hex[1] && $hex[2] == $hex[3] && $hex[4] == $hex[5]) {
                    $hex = $hex[0] . $hex[2] . $hex[4];
                }

                break;

            case 8:
                if ($hex[0] == $hex[1] && $hex[2] == $hex[3] && $hex[4] == $hex[5] && $hex[6] == $hex[7]) {
                    $hex = $hex[0] . $hex[2] . $hex[4] . $hex[6];
                    if (str_ends_with($hex, "F")) {
                        $hex = substr($hex, 0, 3);
                    }
                } elseif (str_ends_with($hex, "FF")) {
                    $hex = substr($hex, 0, 6);
                }

                if (strlen($hex) === 4 && str_ends_with($hex, "F")) {
                    $hex = substr($hex, 0, 3);
                } elseif (str_ends_with($hex, "FF")) {
                    $hex = substr($hex, 0, 6);
                }

                break;

            default:
                return $_hex;
        }

        return "#" . $hex;
    }

    function expandhex(?string $_hex, bool $extended = false): ?string
    {
        $hex = str_lstrip($_hex, "#");
        if (!$hex) {
            return null;
        }

        switch (strlen($hex)) {
            case 3:
                $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
                if ($extended) {
                    $hex .= "FF";
                }
                break;

            case 4:
                $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2] . $hex[3] . $hex[3];
                break;

            default:
                return $_hex;
        }

        return "#" . $hex;
    }


    /**
     * @param ?string $url
     * @return bool
     */
    function is_url(?string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL);
    }

    /**
     * @param string $input
     * @param string $separator
     * @return array|false|string|string[]|null
     */
    function camel2snake(string $input, string $separator = "_")
    {
        return mb_strtolower(str_replace($separator . $separator, $separator, str_replace('.' . $separator, '.', preg_replace('/(?<!^)[A-Z]/', $separator . '$0', str_replace(" ", $separator, $input)))));
    }

    function mb_utf8(string $html)
    {
        $encoding = mb_detect_encoding($html, ['UTF-8','Windows-1252','ISO-8859-1'], true);
        if ($encoding === false) $encoding = 'ISO-8859-1';

        $html = mb_convert_encoding($html, 'UTF-8', $encoding);
        return preg_replace('/^\xEF\xBB\xBF/', '', $html);
    }

    /**
     * @param string $input
     * @param string $separator
     * @return string
     */
    function snake2camel(string $input, string $separator = "_")
    {
        return lcfirst(str_replace(' ', '', mb_ucwords(str_replace($separator, ' ', $input))));
    }

    /**
     * @param string $input
     * @return array|false|string|string[]|null
     */
    function camel2kebab(string $input) 
    { 
        return camel2snake($input, "-");
    }

    /**
     * @param string $input
     * @param string $separator
     * @return string
     */
    function kebab2camel(string $input)
    {
        return snake2camel($input, "-");
    }

    /**
     * @param string $pattern
     * @param array $subject
     * @return array
     */
    function preg_match_array(string $pattern, array $subject)
    {
        $search = [];
        foreach ($subject as $el) {
            if (is_array($el)) {
                $search[] = preg_match_array($pattern, $el);
            } elseif (preg_match($pattern, $el)) {
                $search[] = $el;
            }
        }

        return $search;
    }


    /**
     * @param object|null $object
     * @return bool
     * @throws Exception
     */
    function is_null_object(?object $object)
    {
        if ($object === null) {
            return true;
        }
        return empty(array_filter(cast_to_array($object)));
    }

    function array_unique_object(array $array): array
    {
        $unique = array_keys(array_unique(array_map(fn($e) => spl_object_hash($e), array_filter($array))));
        return array_filter($array, fn($k) => in_array($k, $unique), ARRAY_FILTER_USE_KEY);
    }

    /**
     * @param string $url
     * @param int $status
     * @param $follow_redirects
     * @param $redirect_limitation
     * @return bool
     */
    function valid_response(string $url, int $status = 200, $follow_redirects = true, $redirect_limitation = 10): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $headers = array_filter(get_headers($url), fn($h) => str_starts_with($h, "HTTP/"));
        $header = $follow_redirects ? end($headers) : first($headers);

        $nRedirects = count(array_filter($headers, fn($h) => str_contains($h, "302")));
        if ($nRedirects > $redirect_limitation) {
            return false;
        }

        return preg_match("/^HTTP\/[0-9]\.[0-9] " . $status . "/", $header);
    }

    function base64_image(?string $path, ?string $type = null): ?string
    {
        if ($path === null) {
            return null;
        }

        $type ??= pathinfo($path, PATHINFO_EXTENSION);
        if (!$type) {
            $type = "svg+xml";
        }

        $data = file_exists($path) ? file_get_contents($path) : $path;
        return 'data:image/' . $type . ';base64,' . base64_encode($data);
    }

    /**
     * @param $s
     * @return bool
     */
    function is_base64($s)
    {
        // Check if there are valid base64 characters
        if (!preg_match('/^[a-zA-Z0-9\/\r\n+]*={0,2}$/', $s)) {
            return false;
        }

        // Decode the string in strict mode and check the results
        $decoded = base64_decode($s, true);
        if (false === $decoded) {
            return false;
        }

        // Encode the string again
        if (base64_encode($decoded) != $s) {
            return false;
        }

        return true;
    }

    /**
     * @param $s
     * @return bool
     */
    function is_data($s): bool
    {
        return str_starts_with($s, "data:");
    }

        function is_verbose(): bool
        {
            if (is_cli() && isset($_SERVER["argv"])) {
                foreach ($_SERVER["argv"] as $arg) {
                    if ($arg === "--verbose" || preg_match('/^-v+$/', $arg)) {
                        return true;
                    }
                }
            }
            return false;
        }

        /**
         * Returns the verbosity level as an integer.
         * -v => 1, -vv => 2, -vvv => 3, --verbose => 1
         */
        function verbose_level(): int
        {
            if (is_cli() && isset($_SERVER["argv"])) {
                $level = 0;
                foreach ($_SERVER["argv"] as $arg) {
                    if ($arg === "--verbose") {
                        $level = max($level, 1);
                    } elseif (preg_match('/^-v+$/', $arg)) {
                        $level = max($level, strlen($arg) - 1);
                    }
                }
                return $level;
            }
            return 0;
        }
    function fetch_url(string $url, string $prefix = "file", string $tmpdir = "/tmp"): string
    {
        $tmpfname = tempnam($tmpdir, $prefix);

        $contents = false;
        if (!is_data($url)) {
            $contents = curl_get_contents($url);
        } // Fetch url content..
        else { // Convert base64 url..
            $data = (explode(",", $url)[1] ?? false);
            if ($data && is_base64($data)) {
                $contents = base64_decode($data);
            }
        }

        if ($contents !== false) {
            file_put_contents($tmpfname, $contents);
        }

        return $tmpfname;
    }

    /**
     * @param string $url
     * @param bool $follow_location
     * @param bool $verify_ssl
     * @return bool|string
     */
    function curl_get_contents(string $url, bool $follow_location = true, bool $verify_ssl = false)
    {
        $curl = curl_init();
        $header[0] = "Accept: text/xml,application/xml,application/xhtml+xml,";
        $header[0] .= "text/html;q=0.9,text/plain;q=0.8,image/png,*/*;q=0.5";
        $header[] = "Cache-Control: max-age=0";
        $header[] = "Connection: keep-alive";
        $header[] = "Keep-Alive: 300";
        $header[] = "Accept-Charset: ISO-8859-1,utf-8;q=0.7,*;q=0.7";
        $header[] = "Accept-Language: en-us,en;q=0.5";
        $header[] = "Pragma: ";

        curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, $follow_location);

        curl_exec($curl);
        curl_setopt($curl, CURLOPT_HEADER, 0);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_URL, $url);

        // I have added below two lines.. because of some certificate issue
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, $verify_ssl);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, $verify_ssl);

        $data = curl_exec($curl);

        $code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($code > 400) {
            throw new LogicException("Failed to fetch \"$url\": error $code received.");
        }

        return $data;
    }

    function make_pair(array|string $values): array|false
    {
        $values = is_array($values) ? array_values($values) : str_split($values);
        if (count($values) % 2 != 0) {
            return false;
        }

        // Step by 2: element 2i is the key, 2i+1 its value. The old
        // $values[$i]/$values[$i+1] walked consecutive indices, producing
        // overlapping pairs and consuming only the first half of the input.
        $pairs = [];
        for ($i = 0, $N = count($values); $i < $N; $i += 2) {
            $pairs[$values[$i]] = $values[$i + 1];
        }

        return $pairs;
    }

    /**
     * @param array $array
     * @param int $index
     * @return mixed|null
     */
    function at(array $array, int $index)
    {
        return $array[$index] ?? null;
    }

    function is_tmpfile(string $fname): bool
    {
        return belongs_to($fname, sys_get_temp_dir());
    }

    function unlink_tmpfile(string $fname): bool
    {
        return is_tmpfile($fname) && file_exists($fname) && unlink($fname);
    }

    function belongs_to(string $fname, string $base): bool
    {
        $fname = realpath($fname);
        return $fname !== false && strncmp($fname, $base, strlen($base)) === 0;
    }

    /**
     * @param object $class
     * @return array
     * @throws Exception
     */
    function to_array(object $class)
    {
        return array_transforms(
            fn($k, $v): array => [str_replace("\x00" . get_class($class) . "\x00", "", $k), $v],
            (array)$class
        );
    }

    /**
     * @param object $class
     * @param mixed $needle
     * @return bool
     */
    function in_class(object $class, mixed $needle)
    {
        $haystack = array_filter((array)$class);
        return in_array($needle, $haystack, true);
    }

    /**
     * @param string|null $uuid
     * @return bool
     */
    function is_uuidv4(?string $uuid)
    {
        return is_string($uuid) && !(preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid) !== 1);
    }

    function synopsis(...$args)
    {
        class_synopsis(...$args);
    }

    function class_synopsis(...$args)
    {
        foreach ($args as $object) {
            if (!$object) {
                dump("Object passed to class_synopsis() is null");
                return;
            }
            $objectID = (is_object($object)) ? "Object: 0x" . spl_object_hash($object) . "\n" : "";

            if (!is_object($object) && !is_string($object)) {
                dump($object);
                return;
            }

            $className = (is_string($object) ? $object : get_class($object));
            if (!class_exists($className)) {
                dump("Class \"$className\" not found.");
                return;
            }

            $classParent = get_parent_class($className);
            $classMethods = get_class_methods($className);
            $classVars = get_class_vars($className);

            $classReflection = new ReflectionClass($object);

            $methods = "";
            foreach ($classMethods as $methodName) {
                $params = (new ReflectionMethod($className, $methodName))->getParameters();

                $args = "";
                foreach ($params as $param) {
                    $optional = ($param->isOptional()) ? " = optional" : "";
                    $args .= (!empty($args)) ? ", " : "";
                    $args .= "$" . $param->getName() . $optional;
                }

                $methods .= "\n     public function " . $methodName . "(" . $args . ") { ... }";
            }

            $vars = "";
            foreach ($classVars as $varName => $value) {
                $value = (is_array($value)) ? print_r($value, true) : (
                (is_object($value) && !method_exists($value, '__toString')) ? get_class($value) . "(not is_stringeable)" : $value
                );

                $vars .= (!empty($vars)) ? ",\n" : "";
                $vars .= "     $" . $varName . " = \"" . $value . "\"";
            }

            if (empty($vars)) {
                $vars = "     -- No public variable available";
            }
            if (empty($methods)) {
                $methods = "     -- No public method available";
            }
            $parentName = (!empty($classParent)) ? "            extends " . $classParent : "";

            dump(
                $classReflection,
                $objectID .
                "class " . $className . $parentName . " {\n\n" .
                $vars . "\n" .
                $methods . "\n}\n\nMore information in the ReflectionMethod below.."
            );
        }
    }

    function format_sql(string $sql): string
    {
        return trim(str_replace(
            ["SELECT", ",", "FROM", "WHERE", "INNER", "RIGHT", "LEFT"],
            [PHP_EOL . "SELECT" . PHP_EOL . " ", "," . PHP_EOL . " ", PHP_EOL . "FROM" . PHP_EOL . " ", PHP_EOL . "WHERE" . PHP_EOL . " ", PHP_EOL . "  INNER", PHP_EOL . "  RIGHT", PHP_EOL . "  LEFT"],
            $sql
        ));
    }

    function project_dir()
    {
        return dirname(__DIR__,5);
    }

    /**
     * @param string|null $file
     * @param string|null $class
     * @param string|null $func
     * @return array
     */
    function debug_backtrace_short(?string $file = null, ?string $class = null, ?string $func = null)
    {
        $backtrace = [];

        $debug_backtrace = debug_backtrace();
        foreach ($debug_backtrace as $key => $trace) {
        
            $entry = "";
            if (array_key_exists("file", $trace)) {
                $entry = "./".relative_path($trace["file"], project_dir()) . ":" . $trace["line"];
            }

            $entry .= " >> " .
                (array_key_exists("class", $trace) ? $trace["class"] . "::" : "") .
                (array_key_exists("function", $trace) ? $trace["function"] . "()" : "");

            $backtrace[$key] = $entry;

            $autostop = ($file && array_key_exists("file", $trace) && $trace["file"] == $file);
            $autostop &= ($class && array_key_exists("class", $trace) && $trace["class"] == $class);
            $autostop &= ($func && array_key_exists("function", $trace) && $trace["function"] == $func);

            $autostop |= ($file && str_starts_with($backtrace[$key], $file));
            if ($autostop && $key < count($debug_backtrace) - 1) {
                $backtrace[$key + 1] = "[..]";
                break;
            }
        }

        return $backtrace;
    }

    const SHORTEN_FRONT = -1; // [..] dolor sit amet
    const SHORTEN_MIDDLE = 0; // Lorem ipsum [..] amet
    const SHORTEN_BACK = 1; // Lorem ipsum dolor [..]
    const SHORTEN_BACK_SHRINK = 2; // Lorem ipsum dolor [..]
    const SHORTEN_BACK_EXTEND = 3; // Lorem ipsum dolor [..]
    function str_shorten(?string $haystack, int $length = 100, int $position = SHORTEN_BACK, string $separator = " [..] "): ?string
    {
        // A text that is not there (a thread published without an excerpt) is an empty one: strip_tags() takes no null.
        $haystack = trim(strip_tags($haystack ?? ''));
        if (!$haystack) {
            return $haystack;
        }
        if ($length == 0) {
            return "";
        }

        $nChr = strlen($haystack);

        if ($nChr == 0) {
            return "";
        }

        if ($nChr > $length + strlen($separator)) {
            switch ($position) {
                case SHORTEN_FRONT:
                    // Keep the LAST $length chars: start at $nChr - $length.
                    // Starting at $nChr (the end of the string) returned "".
                    return ltrim($separator) . mb_substr($haystack, $nChr - $length, $length + 1);

                case SHORTEN_MIDDLE:
                    return mb_substr($haystack, 0, $length / 2) . $separator . mb_substr($haystack, $nChr - $length / 2, $length / 2 + 1);

                case SHORTEN_BACK:
                case SHORTEN_BACK_SHRINK:
                case SHORTEN_BACK_EXTEND:
                    if ($position == SHORTEN_BACK_SHRINK) {
                        $pos = strrpos(mb_substr($haystack, 0, $length), ".");
                        if ($pos === false) {
                            return "";
                        }

                        $length = $pos + 1;
                    }

                    if ($position == SHORTEN_BACK_EXTEND) {
                        $pos = strpos(mb_substr($haystack, $length), ".");
                        if ($pos !== false) {
                            $length += $pos + 1;
                        }
                    }

                    return mb_substr($haystack, 0, $length) . rtrim($separator);
            }
        }

        return $haystack;
    }

    /**
     * @param int $digits
     * @return int
     */
    function rand_int(int $digits)
    {
        return rand(pow(10, $digits - 1), pow(10, $digits) - 1);
    }

    function rand_str(?int $length = null, ?string $chars = null): ?string
    {
        if ($length === null) {
            $length = 8;
        }
        if ($chars === null) {
            $chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        }

        if (!$chars) {
            return null;
        }

        $randomString = '';
        for ($i = 0, $N = strlen($chars); $i < $length; $i++) {
            $randomString .= $chars[rand(0, $N - 1)];
        }

        return $randomString;
    }

    /**
     * @param string $str
     * @param int $n
     * @return string
     */
    function rotate_str(string $str, int $n = 1)
    {
        return substr($str, -$n) . substr($str, 0, -$n);
    }


    const     BIT_PREFIX = array("b");
    const    BYTE_PREFIX = array("B", "O", "o");
    const  BINARY_PREFIX = array("", "ki", "mi", "gi", "ti", "pi", "ei", "zi", "yi");
    const DECIMAL_PREFIX = array("", "k", "m", "g", "t", "p", "e", "z", "y");

    function all_in_array(array $needle, array $haystack): bool
    {
        return !array_diff($needle, $haystack);
    }

    function byte2bit(int $num): int
    {
        return 8 * $num;
    } // LMFAO :o)

    function bit2byte(int $num): int
    {
        return $num / 8;
    }

    function byte2str(int $num, array $unitPrefix = DECIMAL_PREFIX): string
    {
        return dec2str($num, $unitPrefix) . BYTE_PREFIX[0];
    }

    function bit2str(int $num, array $unitPrefix = DECIMAL_PREFIX): string
    {
        return dec2str($num, $unitPrefix) . BIT_PREFIX[0];
    }

    function dec2str(int $num, array $unitPrefix = DECIMAL_PREFIX): string
    {
        if (all_in_array($unitPrefix, DECIMAL_PREFIX)) {
            $divider = 1000;
        } elseif (all_in_array($unitPrefix, BINARY_PREFIX)) {
            $divider = 1024;
        } else {
            throw new Exception("Unknown prefix found: \"" . implode(",", $unitPrefix) . "\"");
        }
        $unitPrefix = [''] + $unitPrefix;

        $factor = (int)floor(log($num) / log($divider));
        $quotient = (int)($num / ($divider ** $factor));

        $rest = $num - $factor * ($divider ** $quotient);
        if ($rest > 0) {
            $factor--;
        }

        $quotient = (int)($num / ($divider ** $factor));
        $diff = $factor - count($unitPrefix) + 1;
        if ($diff > 0) {
            $quotient *= $divider ** $diff;
        }

        return strval($factor > 0 ? $quotient . @mb_ucfirst($unitPrefix[$factor] ?? end($unitPrefix)) : $num);
    }

    /**
     * @param string $str
     * @return array|string|string[]|null
     */
    function only_alphachars(string $str)
    {
        return preg_replace("/[^a-zA-Z]+/", "", $str);
    }

    /**
     * @param string $str
     * @return array|string|string[]|null
     */
    function only_alphanumerics(string $str)
    {
        return preg_replace("/[^a-zA-Z0-9]+/", "", $str);
    }

    /**
     * @param string $str
     * @param $brackets
     * @return string
     */
    function trim_brackets(string $str, $brackets = "()")
    {
        $leftBracket = preg_quote($brackets[0] ?? "");
        $rightBracket = preg_quote($brackets[1] ?? "");
        if (!$leftBracket && !$rightBracket) {
            return $str;
        }

        return trim(preg_replace('/\s*' . $leftBracket . '[^)]*' . $rightBracket . '/', '', $str));
    }

    function str2dec(string $str): int
    {
        $val = trim($str);
        if (!preg_match('/^([bo]{0,2})([a-z]{0,2})([0-9]*)/i', strrev($val), $matches)) {
            throw new Exception("Failed to parse string \"" . $str . "\"");
        }

        $val = intval($matches[3] == "" ? 1 : strrev($matches[3]));
        $unitPrefix = mb_strtolower(strrev($matches[2]));
        $units = strrev($matches[1]);

        if (in_array($units, BIT_PREFIX)) {
            $val *= 1;
        } // LMFAO
        if (in_array($units, BYTE_PREFIX)) {
            $val *= 8;
        }
        if ($unitPrefix) {
            $binFactor = array_search($unitPrefix, BINARY_PREFIX);
            $decFactor = array_search($unitPrefix, DECIMAL_PREFIX);
            if (!(($decFactor !== false) xor ($binFactor !== false))) {
                throw new Exception("Unexpected prefix unit \"$unitPrefix\" for \"" . $str . "\"");
            }

            if ($decFactor !== false) {
                $val *= 1000 ** ($decFactor);
            }
            if ($binFactor !== false) {
                $val *= 1024 ** ($binFactor);
            }
        }

        return intval($val);
    }

    /**
     * @param $object_or_class
     * @return int
     */
    function get_depth_class($object_or_class): int
    {
        if (!get_parent_class($object_or_class)) {
            return 0;
        }
        return get_depth_class(get_parent_class($object_or_class)) + 1;
    }

    /**
     * @param $object_or_class
     * @return array
     */
    function get_family_class($object_or_class): array
    {
        $family = [is_object($object_or_class) ? get_class($object_or_class) : $object_or_class];
        while ($object_or_class = get_parent_class($object_or_class)) {
            $family[] = $object_or_class;
        }

        return $family;
    }

    /**
     * @param $object_or_class
     * @param string $property
     * @return string|null
     * @throws ReflectionException
     */
    function property_declarer($object_or_class, string $property): ?string
    {
        $class = is_object($object_or_class) ? get_class($object_or_class) : $object_or_class;

        $reflProperty = new ReflectionProperty($class, $property);
        return $reflProperty->getDeclaringClass()->getName();
    }

    /**
     * @param $object_or_class
     * @param string $property
     * @return string|null
     * @throws ReflectionException
     */
    function property_visibility($object_or_class, string $property): ?string
    {
        $class = is_object($object_or_class) ? get_class($object_or_class) : $object_or_class;

        $reflProperty = new ReflectionProperty($class, $property);
        if ($reflProperty->isPublic()) {
            return "public";
        }
        if ($reflProperty->isProtected()) {
            return "protected";
        }
        if ($reflProperty->isPrivate()) {
            return "private";
        }

        return null;
    }

    /**
     * @param $object_or_class
     * @param string $method
     * @return string|null
     * @throws ReflectionException
     */
    function method_declarer($object_or_class, string $method): ?string
    {
        $class = is_object($object_or_class) ? get_class($object_or_class) : $object_or_class;

        $reflMethod = new ReflectionMethod($class, $method);
        return $reflMethod->getDeclaringClass()->getName();
    }

    /**
     * @param string $builtin
     * @return array|Closure|float|int|string|null
     */
    function builtin_default(string $builtin)
    {
        return match ($builtin) {
            "string" => "",
            "array" => [],
            "float" => NAN,
            "int" => 0,
            "callable" => function () {
            },
            default => null,
        };
    }

    function initialize_property(object $object, string $property): bool
    {
        $reflProperty = new ReflectionProperty(get_class($object), $property);
        $reflProperty->setAccessible(true);

        if ($reflProperty->isInitialized($object)) {
            return true;
        }

        $propertyType = $reflProperty->getType();
        if (!$propertyType->isBuiltin()) {
            return false;
        }

        $builtinDefault = builtin_default($propertyType->getName());
        if (!$propertyType->allowsNull() && $builtinDefault === null) {
            return false;
        }

        $reflProperty->setValue($object, $builtinDefault);
        return true;
    }

    function read_property(object $object, string $property): mixed
    {
        $reflProperty = new ReflectionProperty(get_class($object), $property);
        $reflProperty->setAccessible(true);

        return $reflProperty->getValue($object);
    }

    function write_property(object $object, string $property, $value): mixed
    {
        $reflProperty = new ReflectionProperty(get_class($object), $property);
        $reflProperty->setAccessible(true);

        return $reflProperty->setValue($object, $value);
    }

    /**
     * @param string|array|null $path
     * @param $suffix
     * @param $separator
     * @return string|array|null
     */
    function path_suffix(string|array|null $path, $suffix, $separator = "_"): string|array|null
    {
        if ($path === null) {
            return null;
        }

        if (!is_array($suffix)) {
            $suffix = [$suffix];
        }
        $suffix = implode($separator, array_filter($suffix));

        if (is_array($path)) {
            return array_map(fn($p) => path_suffix($p, $suffix, $separator), $path);
        }

        $path = pathinfo($path);
        $path["dirname"] = $path["dirname"] ?? null;
        if ($path["dirname"]) {
            $path["dirname"] .= "/";
        }
        $path["extension"] = $path["extension"] ?? null;
        if ($path["extension"]) {
            $path["extension"] = "." . $path["extension"];
        }

        $filename = $path["filename"] ?? null;
        $suffix = ($filename && $suffix) ? $separator . $suffix : $suffix;
        return $path["dirname"] . $path["filename"] . $suffix . $path["extension"];
    }

    /**
     * @param string|array|null $path
     * @param $prefix
     * @param $separator
     * @return string|array|null
     */
    function path_prefix(string|array|null $path, $prefix, $separator = "_"): string|array|null
    {
        if ($path === null) {
            return null;
        }

        if (!is_array($prefix)) {
            $prefix = [$prefix];
        }
        $prefix = implode($separator, array_filter($prefix));

        if (is_array($path)) {
            return array_map(fn($p) => path_prefix($p, $prefix, $separator), $path);
        }

        $path = pathinfo($path);
        $path["dirname"] = $path["dirname"] ?? null;
        if ($path["dirname"]) {
            $path["dirname"] .= "/";
        }
        $path["extension"] = $path["extension"] ?? null;
        if ($path["extension"]) {
            $path["extension"] = "." . $path["extension"];
        }

        $filename = $path["filename"] ?? null;
        $prefix = ($filename && $prefix) ? $separator . $prefix : $prefix;

        return $path["dirname"] . $prefix . $path["filename"] . $path["extension"];
    }

    /**
     * @param array|string $separator
     * @param string $str
     * @param $keepDelimiters
     * @param int $limit
     * @return []|string[]
     */
    function explodeByArray(array|string $separator, string $str, $keepDelimiters = false, int $limit = PHP_INT_MAX)
    {
        if ($limit == 0) {
            return [$str];
        }
        if (is_string($separator)) {
            $separator = [$separator];
        }

        if (preg_match('/(.*)(' . implode("|", array_map("preg_quote", $separator)) . ')(.*)/', $str, $matches)) {
            $delimiter = $keepDelimiters ? $matches[2] : "";
            return array_merge(explodeByArray($separator, $matches[1], $keepDelimiters, --$limit), [$delimiter . $matches[3]]);
        }

        return [$str];
    }

    /**
     * @param array|string $separator
     * @param array|null $array
     * @return mixed|string
     */
    function implodeByArray(array|string $separator, ?array $array)
    {
        if (!$array) {
            return "";
        }
        if (is_string($separator)) {
            return implode($separator, $array);
        }

        $str = $array[0];
        for ($i = 1, $iN = count($array), $jN = count($separator); $i < $iN; $i++) {
            $str .= $separator[($i - 1) % $jN] . $array[$i];
        }

        return $str;
    }

    function str_strip(?string $haystack, array|string $lneedle = " ", array|string $rneedle = " ", bool $recursive = true): ?string
    {
        return str_rstrip(str_lstrip($haystack, $lneedle, $recursive), $rneedle, $recursive);
    }

    function str_rstrip(?string $haystack, array|string $needle = " ", bool $recursive = true): ?string
    {
        if ($haystack === null) {
            return null;
        }
        if (is_array($needle)) {
            $lastHaystack = null;
            while ($haystack != $lastHaystack) {
                $lastHaystack = $haystack;
                foreach ($needle as $n) {
                    $haystack = str_rstrip($haystack, $n);
                }
            }

            return $haystack;
        }

        $needleLength = strlen($needle);
        if (!$needleLength) {
            return $haystack;
        }

        while (strrpos($haystack, $needle) !== false && strlen($haystack) === strrpos($haystack, $needle) + $needleLength) {
            $haystack = substr($haystack, 0, strlen($haystack) - $needleLength);
            if (!$recursive) {
                break;
            }
        }

        return $haystack;
    }

    function str_lstrip(?string $haystack, array|string $needle = " ", bool $recursive = true): ?string
    {
        if ($haystack === null) {
            return null;
        }
        if (is_array($needle)) {
            $lastHaystack = null;
            while ($haystack != $lastHaystack) {
                $lastHaystack = $haystack;
                foreach ($needle as $n) {
                    $haystack = str_lstrip($haystack, $n);
                }
            }

            return $haystack;
        }

        $needleLength = strlen($needle);
        if (!$needleLength) {
            return $haystack;
        }

        while (strrpos($haystack, $needle) !== false && !empty($needle) && str_starts_with($haystack, $needle)) {
            $haystack = substr($haystack, $needleLength);
            if (!$recursive) {
                break;
            }
        }

        return $haystack;
    }

    function strmultipos(string $haystack, array $needles, int $offset = 0): int|false
    {
        for ($i = $offset, $N = strlen($haystack); $i < $N; $i++) {
            if (in_array($haystack[$i], $needles)) {
                return $i;
            }
        }

        return false;
    }

    /**
     * @param object|array $array
     * @return mixed|null
     */
    function begin(object|array $array)
    {
        return array_values(array_slice($array, 0, 1))[0] ?? null;
    }

    function head(object|array $array): mixed
    {
        return begin($array);
    }

    /**
     * @param object|array $array
     * @return false|mixed|null
     */
    function last(object|array &$array)
    {
        return end($array) ?? null;
    }

    function tail(object|array $array, int $length = -1, bool $preserve_keys = false): array
    {
        return array_slice($array, -min(count($array) - 1, $length), null, $preserve_keys);
    }

    /**
     * @param object|array|null $array
     * @return mixed|null
     */
    function first(object|array|null $array)
    {
        return $array ? begin($array) : null;
    }

    /**
     * @param object|array|null $array
     * @return mixed|null
     */
    function second(object|array|null $array)
    {
        return first(tail($array));
    }

    /**
     * @param object|array|null $array
     * @return mixed|null
     */
    function third(object|array|null $array)
    {
        return second(tail($array));
    }

    /**
     * @param object|array|null $array
     * @return mixed|null
     */
    function fourth(object|array|null $array)
    {
        return third(tail($array));
    }

    /**
     * @param object|array|null $array
     * @return mixed|null
     */
    function fifth(object|array|null $array)
    {
        return fourth(tail($array));
    }

    /**
     * @param array $arr1
     * @param array $arr2
     * @return float
     */
    function distance(array $arr1, array $arr2)
    {
        $min = min(count($arr1), count($arr2));
        if ($min == count($arr1)) {
            $arr2 = array_pad($arr2, $min, 0);
        }
        if ($min == count($arr2)) {
            $arr1 = array_pad($arr1, $min, 0);
        }

        return sqrt(array_sum(array_map(fn($d1, $d2) => (intval($d1) - intval($d2)) * (intval($d1) - intval($d2)), $arr1, $arr2)));
    }

    /**
     * @param $handle
     * @param int $bytes
     * @param int $rollback
     * @return string|null
     */
    function fread2($handle, int $bytes = 1, int $rollback = 0): ?string
    {
        if (!$handle) {
            return null;
        }
        $ftell = ftell($handle);
        if ($rollback) {
            fseek($handle, $ftell - $rollback);
        }

        return fread($handle, $bytes);
    }

    function digits(int $num, int $ndigits): string
    {
        $str = strval($num);

        $length = strlen($str);
        for ($i = $length; $i < $ndigits; $i++) {
            $str = '0' . $str;
        }

        return $str;
    }

    /**
     * @param array $array
     * @param $position
     * @return false|mixed
     */
    function closest(array $array, $position = -1)
    {
        return $array[$position] ?? ($position < 0 ? ($array[0] ?? false) : end($array));
    }

    /**
     * @param string|null $str
     * @return bool
     */
    function is_html(?string $str)
    {
        return $str !== null && $str != strip_tags($str);
    }

    /**
     * @param $value
     * @return bool
     */
    function is_stringeable($value)
    {
        return (!is_object($value) && !is_array($value)) || ((is_object($value)) && method_exists($value, '__toString'));
    }

    /**
     * @param string $extension
     * @param string $suffix
     * @param string $prefix
     * @return false|resource
     */
    function tmpfile2(string $extension = "", string $suffix = "", string $prefix = "")
    {
        $extension = $extension ? '.' . $extension : "";
        $fname = tempnam(sys_get_temp_dir(), $prefix);
        rename($fname, $fname .= $suffix . $extension);

        return fopen($fname, "w");
    }

    const HEADER_FOLLOW_REDIRECT = 1;
    /**
     * @param string $filename
     * @param int $mode
     * @param bool $use_include_path
     * @param $context
     * @param int $offset
     * @param int|null $length
     * @return false|string
     */
    function file_get_contents2(string $filename, int $mode = HEADER_FOLLOW_REDIRECT, bool $use_include_path = false, $context = null, int $offset = 0, ?int $length = null)
    {
        if ($mode == HEADER_FOLLOW_REDIRECT) {
            get_headers2($filename, $filename);
        }
        return file_get_contents($filename, $use_include_path, $context, $offset, $length);
    }

    /**
     * @param string $url
     * @param $redirect
     * @param int $mode
     * @return array|false
     */
    function get_headers2(string $url, &$redirect = null, int $mode = HEADER_FOLLOW_REDIRECT)
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return [500, "application/octet-stream"];
        }
        if ($mode != HEADER_FOLLOW_REDIRECT) {
            return get_headers($url);
        }

        do {

            $http_response_header = []; // Special PHP variable
            $context = [];
            $context["http"] = ["follow_location" => false];
            
            get_headers($url, false, stream_context_create($context));

            $pattern = "/^Location:\s*(.*)$/i";
            $location_headers = preg_grep($pattern, $http_response_header);
            
            $matches = [];
            $repeat = !empty($location_headers) && preg_match($pattern, array_values($location_headers)[0], $matches);
            if ($repeat) {
                $url = $matches[1];
            }

        } while ($repeat);

        $redirect = $url;

        return $http_response_header;
    }

    function dir_empty(string $dir): bool
    {
        return !file_exists($dir) || (is_dir($dir) && !(new FilesystemIterator($dir))->valid());
    }

    function is_binary(string $str) {
        return preg_match('~[^\x20-\x7E\t\r\n]~', $str) > 0;
    }
    /**
     * @param string $filename
     * @param int $mode
     * @return false|string|null
     */
    function mime_content_type2(string $filename, int $mode = HEADER_FOLLOW_REDIRECT)
    {
        // Search by looking at the header if url format
        if (filter_var($filename, FILTER_VALIDATE_URL)) {
            $headers = get_headers2($filename, $filename, $mode);
            if (strpos($headers[0], '200')) {
                return str_lstrip(first(array_starts_with($headers, "Content-Type", ARRAY_USE_VALUES)), "Content-Type: ");
            }
        }

        if (!file_exists($filename)) {
            return null;
        }

        try {
            return mime_content_type($filename);
        } catch (TypeError|Exception $e) {
            return null;
        }
    }

    /**
     * @param $str
     * @return string
     */
    function str2bin($str)
    {
        $characters = str_split($str);

        $binary = [];
        foreach ($characters as $character) {
            $data = unpack('H*', $character);
            $binary[] = base_convert($data[1], 16, 2);
        }

        return implode(' ', $binary);
    }

    /**
     * @param $binary
     * @return string|null
     */
    function bin2str($binary)
    {
        $binaries = explode(' ', $binary);

        $str = null;
        foreach ($binaries as $binary) {
            $str .= pack('H*', dechex(bindec($binary)));
        }

        return $str;
    }

    /**
     * @param mixed $objectOrClass
     * @param $interface
     * @return bool
     */
    function class_implements_interface(mixed $objectOrClass, $interface)
    {
        if (!is_object($objectOrClass) && !is_string($objectOrClass)) {
            return false;
        }

        $class = is_object($objectOrClass) ? get_class($objectOrClass) : $objectOrClass;
        if (!class_exists($class)) {
            return false;
        }

        $classImplements = class_implements($class);
        return array_key_exists($interface, $classImplements);
    }

    /**
     * @param array|object|string|null $arrayOrObjectOrClass
     * @return array|array[]|null[]|object|object[]|string|string[]|string[][]|null
     */
    function class_name(array|object|string|null $arrayOrObjectOrClass)
    {
        return class_basename($arrayOrObjectOrClass);
    }

    /**
     * @param array|object|string|null $arrayOrObjectOrClass
     * @return array|array[]|null[]|object|object[]|string|string[]|string[][]|null
     */
    function class_basename(array|object|string|null $arrayOrObjectOrClass)
    {
        if (!$arrayOrObjectOrClass) {
            return $arrayOrObjectOrClass;
        }
        if (is_array($arrayOrObjectOrClass)) {
            return array_map(fn($a) => class_basename($a), $arrayOrObjectOrClass);
        }

        $class = is_object($arrayOrObjectOrClass) ? get_class($arrayOrObjectOrClass) : $arrayOrObjectOrClass;
        $class = explode("::", $class)[0];

        return str_replace("/", "\\", basename(str_replace('\\', '/', $class)));
    }

    function basenameWithoutExtension(string $path): string
    {
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        return basename($path, $ext ? "." . $ext : "");
    }

    /**
     * @param array|object|string|null $arrayOrObjectOrClass
     * @return array|array[]|null[]|object|object[]|string|string[]|string[][]|null
     */
    function class_namespace(array|object|string|null $arrayOrObjectOrClass)
    {
        return class_dirname($arrayOrObjectOrClass);
    }

    /**
     * @param array|object|string|null $arrayOrObjectOrClass
     * @return array|array[]|null[]|object|object[]|string|string[]|string[][]|null
     */
    function class_dirname(array|object|string|null $arrayOrObjectOrClass)
    {
        if (!$arrayOrObjectOrClass) {
            return $arrayOrObjectOrClass;
        }
        if (is_array($arrayOrObjectOrClass)) {
            return array_map(fn($a) => class_dirname($a), $arrayOrObjectOrClass);
        }

        $class = is_object($arrayOrObjectOrClass) ? get_class($arrayOrObjectOrClass) : $arrayOrObjectOrClass;
        $dirname = str_replace("/", "\\", dirname(str_replace('\\', '/', $class)));
        return $dirname == "." ? "" : $dirname;
    }

    function is_cli(): bool
    {
        return (php_sapi_name() == "cli");
    }

    if(!function_exists('mb_lcfirst')) {
        function mb_lcfirst(array|string $str, ?string $encoding = null): array|string
        {
            if (is_array($str)) {
                $array = [];
                foreach ($str as $s) {
                    $array[] = mb_lcfirst($s, $encoding);
                }

                return $array;
            }

            return mb_strtolower(mb_substr($str, 0, 1, $encoding), $encoding) . mb_substr($str, 1, null, $encoding);
        }
    }

    function mb_lcwords(array|string $str, ?string $encoding = null, string $separators = " '\t\r\n\f\v"): array|string
    {
        if (is_array($str)) {
            $array = [];
            foreach ($str as $s) {
                $array[] = mb_lcwords($s, $encoding, $separators);
            }

            return $array;
        }

        return implode("", array_map(function ($s) use ($encoding, $separators) {
            $s1 = ltrim($s, $separators);
            return $s != $s1 ? $s[0] . mb_lcfirst($s1, $encoding) : mb_lcfirst($s, $encoding);
        }, explodeByArray(is_array($separators) ? $separators : str_split($separators), $str, true)));
    }

    if(!function_exists('mb_ucfirst')) { 
        function mb_ucfirst(array|string $str, ?string $encoding = null): array|string
        {
            if (is_array($str)) {
                $array = [];
                foreach ($str as $s) {
                    $array[] = mb_ucfirst($s, $encoding);
                }

                return $array;
            }

            return mb_strtoupper(mb_substr($str, 0, 1, $encoding), $encoding) . mb_substr($str, 1, null, $encoding);
        }
    }

    function mb_ucwords(array|string $str, ?string $encoding = null, string $separators = " '\t\r\n\f\v"): array|string
    {
        if (is_array($str)) {
            $array = [];
            foreach ($str as $s) {
                $array[] = mb_ucwords($s, $encoding, $separators);
            }

            return $array;
        }

        return implode("", array_map(function ($s) use ($encoding, $separators) {
            $s1 = ltrim($s, $separators);
            return $s != $s1 ? $s[0] . mb_ucfirst($s1, $encoding) : mb_ucfirst($s, $encoding);
        }, explodeByArray(is_array($separators) ? $separators : str_split($separators), $str, true)));
    }

    /**
     * @param string $separator
     * @param array ...$attributes
     * @return string
     */
    function implode_attributes(string $separator, array ...$attributes)
    {
        $attributes = array_merge(...$attributes);
        return trim(implode($separator, array_map(fn($k) => trim($k) . "=\"" . trim($attributes[$k] ?? "") . "\"", array_keys($attributes))));
    }

    function explode_attributes(string $separator, string $attributes): array
    {
        $list = [];
        foreach (explode($separator, $attributes) as $entry) {
            $explode = explode("=", $entry, 2);
            $key = head($explode);
            if ($key) {
                $list[$key] = $explode[1] ?? "";
            }
        }

        return $list;
    }

    /**
     * @param array ...$attributes
     * @return string
     */
    function html_attributes(array ...$attributes)
    {
        return implode_attributes(" ", ...$attributes);
    }

    function browser_name(): ?string
    {
        return get_browser2()["name"] ?? null;
    }

    function browser_platform(): ?string
    {
        return get_browser2()["platform"] ?? null;
    }

    function browser_version(): ?string
    {
        return get_browser2()["version"] ?? null;
    }

    /**
     * @param string|null $userAgent
     * @return array
     */
    function get_browser2(?string $userAgent = null)
    {
        $userAgent = $userAgent ?? $_SERVER['HTTP_USER_AGENT'] ?? null;
        if ($userAgent == null) {
            return [];
        }

        $platform = "unknown";
        if (preg_match('/android/i', $userAgent)) {
            $platform = 'android';
        } elseif (preg_match('/linux/i', $userAgent)) {
            $platform = 'linux';
        } elseif (preg_match('/macintosh|mac os x/i', $userAgent)) {
            $platform = 'apple';
        } elseif (preg_match('/windows|win32/i', $userAgent)) {
            $platform = 'windows';
        }

        $name = "Unknown";
        if (preg_match('/MSIE/i', $userAgent) && !preg_match('/Opera/i', $userAgent)) {
            $name = "MSIE";
        } elseif (preg_match('/Firefox/i', $userAgent)) {
            $name = "Firefox";
        } elseif (preg_match('/OPR/i', $userAgent)) {
            $name = "Opera";
        } elseif (preg_match('/Chrome/i', $userAgent) && !preg_match('/Edge/i', $userAgent)) {
            $name = "Chrome";
        } elseif (preg_match('/Safari/i', $userAgent) && !preg_match('/Edge/i', $userAgent)) {
            $name = "Safari";
        } elseif (preg_match('/Netscape/i', $userAgent)) {
            $name = "Netscape";
        } elseif (preg_match('/Edge/i', $userAgent)) {
            $name = "Edge";
        } elseif (preg_match('/Trident/i', $userAgent)) {
            $name = "MSIE";
        }

        $device = "computer";
        if (preg_match('/tablet|ipad/i', $userAgent)) {
            $device = 'tablet';
        } elseif (preg_match('/mobile|iphone|ipod/i', $userAgent)) {
            $device = 'mobile';
        }


        $known = implode("|", ['Version', $name, 'other']);
        preg_match_all('#(?<browser>' . $known . ')[/ ]+(?<version>[0-9.|a-zA-Z.]*)#', $userAgent, $matches);

        $version = "";
        if (count($matches['browser']) == 1) {
            $version = $matches['version'][0] ?? null;
        } else {
            //we will have two since we are not using 'other' argument yet
            //see if version is before or after the name
            if (strripos($userAgent, "Version") < strripos($userAgent, $name)) {
                $version = $matches['version'][0] ?? null;
            } else {
                $version = $matches['version'][1] ?? null;
            }
        }

        if (!$version) {
            $version = "?";
        }

        return [
            'user_agent' => $userAgent,
            'name' => $name,
            'version' => $version,
            'device' => $device,
            'platform' => $platform
        ];
    }

    /**
     * @param array $array
     * @param $limit
     * @return array
     */
    function array_limit(array &$array, $limit = -1)
    {
        if ($limit < 0) {
            return $array;
        }
        return array_splice($array, 0, $limit);
    }

    function array_concat(array ...$arrays): array
    {
        $array = [];
        foreach ($arrays as $arr) {
            foreach ($arr as $key => $element) {
                $array[] = $element;
            }
        }

        return $array;
    }

    function array_clear(array &$array)
    {
        // while (array_pop($array)) {} used to stop at the first falsy element
        // (0, "", null, false, [], "0"), leaving everything before it — so any
        // array containing a falsy value was only partially cleared.
        $array = [];
    }

    function array_prepend(array &$array, ...$value): int
    {
        return array_unshift($array, ...$value);
    }

    function array_append(array &$array, ...$value): int
    {
        return array_push($array, ...$value);
    }

    /**
     * @return array|mixed
     */
    function array_append_recursive()
    {
        $arrays = func_get_args();
        $base = array_shift($arrays);

        if (!is_array($base)) {
            $base = empty($base) ? [] : array($base);
        }

        foreach ($arrays as $append) {
            if (!is_array($append)) {
                $append = array($append);
            }
            foreach ($append as $key => $value) {
                if (!array_key_exists($key, $base) and !is_numeric($key)) {
                    $base[$key] = $value;
                    continue;
                }

                if (is_array($value) or is_array($base[$key])) {
                    $base[$key] = array_append_recursive($base[$key], $value);
                } elseif (is_numeric($key)) {
                    if (!in_array($value, $base)) {
                        $base[] = $value;
                    }
                } else {
                    $base[$key] = $value;
                }
            }
        }

        return $base;
    }

    function browser_supports_webp(): bool
    {
        if (str_contains($_SERVER['HTTP_ACCEPT'] ?? "", 'image/webp')) {
            return true;
        }

        if (browser_name() == "Safari" && version_compare("14.0", browser_version()) < 0) {
            return true;
        }
        if (browser_name() == "Chrome" && version_compare("23.0", browser_version()) < 0) {
            return true;
        }
        if (browser_name() == "Firefox" && version_compare("65.0", browser_version()) < 0) {
            return true;
        }
        if (browser_name() == "Edge" && version_compare("1809", browser_version()) < 0) {
            return true;
        }
        if (browser_name() == "Opera" && version_compare("12.1", browser_version()) < 0) {
            return true;
        }

        return false;
    }

    function array_insert(array $array, bool|int|string $index = false, ...$val): array
    {
        $keys = array_keys($array);

        $pos = $index === false ? false : array_search($index, $keys, true);
        if ($pos === false) {
            $pos = count($array);
        }

        return array_merge(array_slice($array, 0, $pos), $val, array_slice($array, $pos));
    }

    function array_remove(array $array, ...$values): array
    {
        foreach ($values as $val) {
            foreach (array_keys($array, $val) as $key) {
                unset($array[$key]);
            }
        }

        return $array;
    }

    /**
     * @param array $array
     * @param $key
     * @return mixed
     */
    function next_key(array $array, $key): mixed
    {
        $keys = array_keys($array);

        $position = array_search($key, $keys);
        if (isset($keys[$position + 1])) {
            return $keys[$position + 1];
        }

        return false;
    }

    /**
     * @param array $array
     * @return bool
     */
    function is_multidimensional(array $array)
    {
        return count($array) !== count($array, COUNT_RECURSIVE);
    }

    function array_filter_column(array $array, ?callable $callback = null): array
    {
        if (!is_multidimensional($array)) {
            return $array;
        }

        $empties = [];
        foreach (first($array) as $key => $_) {
            $error = array_column($array, $key);
            if (empty(array_filter($error, $callback))) {
                $empties[] = $key;
            }
        }

        foreach ($array as &$entry) {
            $entry = array_filter($entry, fn($e) => !in_array($e, $empties));
        }

        return $array;
    }

    function array_search_by(?array $array, string $column, mixed $value): ?array
    {
        if ($array === null) {
            return null;
        }
        if (!is_multidimensional($array)) {
            return $array;
        }

        $results = [];
        foreach ($array as $k => $v) {
            if (!is_array($v)) {
                continue;
            }
            if (!array_key_exists($column, $v)) {
                continue;
            }

            if ($v[$column] === $value) {
                $results[] = $v;
            }
        }

        return $results ?: null;
    }


    /**
     * @param array $data
     * @param bool $duplicates
     * @param int|null $limit
     * @return array|array[]
     */
    function get_permutations(array $data = [], bool $duplicates = true, ?int $limit = null)
    {
        $permutations = [[]];

        for ($i = 0, $N = $limit ? min(count($data), $limit) : count($data); $i < $N; $i++) {
            $buffer = [];
            foreach ($permutations as $permutation) {
                foreach ($data as $inputValue) {
                    $buffer[] = array_merge($permutation, [$inputValue]);
                }

                if (!$duplicates) {
                    // $buffer holds arrays; array_unique defaults to SORT_STRING,
                    // which casts every sub-array to the literal "Array" (with a
                    // notice) so all compare equal and all but the first survive.
                    $buffer = array_unique($buffer, SORT_REGULAR);
                }
            }

            $permutations = $buffer;
        }

        return $permutations;
    }

    function dumplight(mixed $value)
    {
        echo "<pre>";
        print_r($value);
        echo "</pre>";
    }

    /**
     * @param $text
     * @return array|string|string[]|null
     */
    function str_accent($text)
    {
        $utf8 = array(
            '/[áàâãªä]/u' => 'a',
            '/[ÁÀÂÃÄ]/u' => 'A',
            '/[ÍÌÎÏ]/u' => 'I',
            '/[íìîï]/u' => 'i',
            '/[éèêë]/u' => 'e',
            '/[ÉÈÊË]/u' => 'E',
            '/[óòôõºö]/u' => 'o',
            '/[ÓÒÔÕÖ]/u' => 'O',
            '/[úùûü]/u' => 'u',
            '/[ÚÙÛÜ]/u' => 'U',
            '/ç/' => 'c',
            '/Ç/' => 'C',
            '/ñ/' => 'n',
            '/Ñ/' => 'N',
            '/–/' => '-', // UTF-8 hyphen to "normal" hyphen
            '/[’‘‹›‚]/u' => ' ', // Literally a single quote
            '/[“”«»„]/u' => ' ', // Double quote
            '/ /' => ' ', // nonbreaking space (equiv. to 0x160)
        );

        return preg_replace(array_keys($utf8), array_values($utf8), $text);
    }

    function is_multiligne(string $str): bool
    {
        return strstr($str, PHP_EOL);
    }

    /**
     * @param string $path
     * @param string|null $extension
     * @return string
     */
    function pathinfo_extension(string $path, ?string $extension = null)
    {
        $info = pathinfo($path);
        $extension = $extension ?? $info["extension"] ?? "";

        $dirname = $info['dirname'] ? $info['dirname'] . "/" : '';
        return $dirname . $info['filename'] . ($extension ? "." . $extension : "");
    }

    function relative_path(string $from, string $to): string
    {
        // Normalize paths
        $from = str_replace('\\', '/', realpath($from) ?: $from);
        $to   = str_replace('\\', '/', realpath($to)   ?: $to);
    
        $fromParts = explode('/', rtrim($from, '/'));
        $toParts   = explode('/', rtrim($to, '/'));
    
        // Find length of common path
        $length = min(count($fromParts), count($toParts));
        $commonLength = 0;
        for ($i = 0; $i < $length; $i++) {
            if ($fromParts[$i] !== $toParts[$i]) {
                break;
            }
            $commonLength++;
        }
    
        // Go up from $to to the common base
        $relParts = array_fill(0, count($toParts) - $commonLength, '..');
    
        // Append remaining parts from $from
        $relParts = array_merge($relParts, array_slice($fromParts, $commonLength));

        return implode('/', $relParts);
    }

    function pathinfo_relationship(string $path): ?string
    {
        if (is_multiligne($path)) {
            return null;
        }

        $extension = pathinfo(parse_url($path, PHP_URL_PATH), PATHINFO_EXTENSION);
        if (empty($extension)) {
            return null;
        }

        return match ($extension) {
            "ico" => "icon",
            "css" => "stylesheet",
            "js" => "javascript",
            default => "preload",
        };
    }

    function is_associative(array $arr): bool
    {
        if (!$arr) {
            return false;
        }

        $keys = array_keys($arr);
        foreach ($keys as $key) {
            if (gettype($key) != "integer") {
                return true;
            }
        }

        return $keys !== range(0, count($arr) - 1);
    }

    /**
     * @param $a
     * @return bool
     */
    function array_is_nested($a)
    {
        $rv = array_filter($a, 'is_array');
        if (count($rv) > 0) {
            return true;
        }
        return false;
    }

    /**
     * @param $array
     * @param array|string|int $old
     * @param array|string|int $new
     * @return array|mixed
     * @throws Exception
     */
    function array_replace_keys($array, array|string|int $old, array|string|int $new)
    {
        if (gettype($old) == "array" && gettype($new) == "array") {
            foreach ($old as $i => $_) {
                $array = array_replace_keys($array, $_, $new[$i]);
            }

            return $array;
        } elseif (gettype($old) == "array" || gettype($new) == "array") {
            if (gettype($new) != gettype($old)) {
                throw new Exception(__FUNCTION__ . "() : Argument #2 (\$new) must be of same type as argument #1 (\$old)");
            }
        }

        $keys = array_keys($array);
        $idx = array_search($old, $keys);

        array_splice($keys, $idx, 1, $new);
        return array_combine($keys, array_values($array));
    }

    /**
     * Recursively apply a callback to all leaf values in a nested array
     *
     * Traverses a multi-dimensional array and applies a callback function to each
     * leaf (non-array) value, preserving the array structure and keys.
     *
     * Performance: Creates a complete copy of the array structure. For side-effect-only
     * operations (where the return value is discarded), use array_walk_recursive() instead.
     *
     * @param callable $callback Function to apply to each leaf value. Signature: function($value): mixed
     * @param array $array The nested array to process
     * @return array New array with callback applied to all leaf values, structure preserved
     *
     * @see array_walk_recursive() For side-effect-only operations without creating a copy
     * @see array_leaves() To extract all leaf values into a flat array
     * @see count_leaves() To count leaf nodes
     */
    function array_map_recursive(callable $callback, array $array): array
    {
        $func = function ($item) use (&$func, &$callback) {
            return is_array($item) ? array_map($func, $item) : $callback($item);
        };

        return array_map($func, $array);
    }

    /**
     * Extract all leaf values from a nested array into a flat array
     *
     * Recursively traverses a multi-dimensional array and returns all non-array
     * values (leaves) as a flat, numerically-indexed array. Keys are not preserved.
     *
     * @param array $array The nested array to extract leaves from
     * @return array Flat array containing all leaf values
     *
     * @see array_map_recursive() To transform leaf values while preserving structure
     * @see count_leaves() To count leaf nodes
     * @see json_leaves() For JSON-specific leaf extraction with reference support
     */
    function array_leaves(array $array): array
    {
        $leaves = [];
        array_map_recursive(function($v) use (&$leaves) {
            $leaves[] = $v;
        }, $array);

        return $leaves;
    }

    /**
     * Count the number of leaf nodes in a nested array
     *
     * @param array $array The nested array to count leaves in
     * @return int Total number of non-array values (leaf nodes)
     *
     * @see array_leaves() To extract the actual leaf values
     */
    function count_leaves(array $array)
    {
        $counter = 0;
        array_map_recursive(function ($k) use (&$counter) {
            $counter++;
        }, $array);
        return $counter;
    }

    /**
     * Check if a JPEG image file uses the CMYK colour space.
     * @param string $path The path to the file.
     * @return bool
     */
    function is_cmyk(string $path)
    {
        if (!$path || !file_exists($path)) {
            return false;
        }

        $imagesize = @getimagesize($path);
        if ($imagesize === false) {
            return false;
        }
        return array_key_exists('mime', $imagesize) && 'image/jpeg' == $imagesize['mime'] &&
            array_key_exists('channels', $imagesize) && 4 == $imagesize['channels'];
    }

    /**
     * @param string $path
     * @return bool
     */
    function is_rgb(string $path)
    {
        if (!$path || !file_exists($path)) {
            return false;
        }

        $imagesize = @getimagesize($path);
        if ($imagesize === false) {
            return false;
        }
        return array_key_exists('channels', $imagesize) && 3 == $imagesize['channels'];
    }

    /**
     * @param string $path
     * @param string $name
     * @return mixed|null
     * @throws ImagickException
     */
    function cmyk_profile(string $path, string $name)
    {
        $image = new Imagick($path); // load image
        return $image->getImageProfiles($name)[$name] ?? null; // get profiles
    }

    function write_cmyk_profiles(string $path, array $profiles)
    {
        $image = new Imagick($path); // load image
        foreach ($profiles as $name => $data) {
            $image->profileImage($name, $data);
        }

        $image->setImageColorSpace(is_cmyk($path) ? Imagick::COLORSPACE_CMYK : Imagick::COLORSPACE_RGB);
        $image->writeImage($path);
    }

    /**
     * @param string $path
     * @param string $name
     * @param $data
     * @return void
     * @throws ImagickException
     */
    function write_cmyk_profile(string $path, string $name, $data)
    {
        $image = new Imagick($path); // load image
        $image->profileImage($name, $data);
        $image->setImageColorSpace(is_cmyk($path) ? Imagick::COLORSPACE_CMYK : Imagick::COLORSPACE_RGB);
        $image->writeImage($path);
    }

    /**
     * @param string $path
     * @return array
     * @throws ImagickException
     */
    function cmyk_profiles(string $path)
    {
        $image = new Imagick($path); // load image
        return $image->getImageProfiles(); // get profiles
    }

    /**
     * @param string $path
     * @return mixed|null
     * @throws ImagickException
     */
    function cmyk_icc_profile(string $path)
    {
        $image = new Imagick($path); // load image
        return $image->getImageProfiles('icc')['icc'] ?? null; // get profiles
    }

    function cmyk2rgb(string $path): string
    {
        if (is_rgb($path)) {
            return $path;
        }

        if (!class_exists("Imagick")) {
            throw new Exception(__FUNCTION__ . "(): Imagick driver not found.");
        }

        $image = new Imagick($path);
        $image->transformImageColorspace(Imagick::COLORSPACE_SRGB);
        $image->writeImage($path);
        $image->destroy();

        return $path;
    }

    /**
     * @param $path
     * @return string
     * @throws ImagickException
     */
    function rgb2cmyk($path): string
    {
        if (is_cmyk($path)) {
            return $path;
        }
        if (!class_exists("Imagick")) {
            throw new Exception(__FUNCTION__ . "(): Imagick driver not found.");
        }

        $image = new Imagick($path);
        $image->transformImageColorspace(Imagick::COLORSPACE_CMYK);
        $image->writeImage($path);
        $image->destroy();

        return $path;
    }

    /**
     * @param $dir
     * @return bool
     */
    function is_emptydir($dir): bool
    {
        if(!is_dir($dir)) return false;
         
        $handle = opendir($dir);
        while (false !== ($entry = readdir($handle))) {
            if ($entry != "." && $entry != "..") {
                closedir($handle);
                return false;
            }
        }

        closedir($handle);
        return true;
    }

    const ARRAY_USE_KEYS = 0;
    const ARRAY_USE_VALUES = 1;
    /**
     * @param array $haystack
     * @param string $needle
     * @param int $mode
     * @return array
     */
    function array_contains(array $haystack, string $needle, int $mode = ARRAY_USE_KEYS)
    {
        // ARRAY_FILTER_USE_BOTH passes ($value, $key) — the params were named
        // ($k, $v) but bound the other way round, so ARRAY_USE_KEYS actually
        // matched values and vice versa (cf. the correct array_starts_with below).
        return array_filter($haystack, fn($v, $k) => str_contains($mode == ARRAY_USE_KEYS ? $k : $v, $needle), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * @param array $haystack
     * @param string $needle
     * @param int $mode
     * @return array
     */
    function array_starts_with(array $haystack, string $needle, int $mode = ARRAY_USE_KEYS)
    {
        return array_filter($haystack, fn($v,$k) => str_starts_with($mode == ARRAY_USE_KEYS ? $k : $v, $needle), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * @param array $haystack
     * @param string $needle
     * @param int $mode
     * @return array
     */
    function array_ends_with(array $haystack, string $needle, int $mode = ARRAY_USE_KEYS)
    {
        return array_filter($haystack, fn($v,$k) => str_ends_with($mode == ARRAY_USE_KEYS ? $k : $v, $needle), ARRAY_FILTER_USE_BOTH);
    }

    const FORMAT_IDENTITY = 0; // "no changes"
    const FORMAT_TITLECASE = 1; // Lorem Ipsum Dolor Sit Amet
    const FORMAT_SENTENCECASE = 2; // Lorem ipsum dolor sit amet
    const FORMAT_LOWERCASE = 3; // lorem ipsum dolor sit amet
    const FORMAT_UPPERCASE = 4; // LOREM IPSUM DOLOR SIT AMET

    /**
     * @param $array
     * @param ...$values
     * @return bool
     */

    if(!function_exists('array_any')) { 

        function array_any($array, ...$values)
        {
            if (empty($values)) {
                return true;
            }
            foreach ($values as $value) {
                if (in_array($value, $array)) {
                    return true;
                }
            }

            return false;
        }
    }

    /**
     * @param $array
     * @param ...$values
     * @return bool
     */
    function array_every($array, ...$values)
    {
        foreach ($values as $value) {
            if (!in_array($value, $array)) {
                return false;
            }
        }

        return true;
    }

    function call_user_func_with_defaults(callable $fn, ...$args): mixed
    {
        $reflectionFn = new ReflectionFunction($fn);

        $nArgs = count($args);
        if ($reflectionFn->getNumberOfRequiredParameters() > $nArgs) {
            throw new InvalidArgumentException("Missing arguments (" . $nArgs . " provided out of " . $reflectionFn->getNumberOfParameters() . ")  in the callable function \"" . $reflectionFn->getName() . "\"");
        }

        return call_user_func($fn, ...array_slice($args, 0, min($nArgs, $reflectionFn->getNumberOfParameters())));
    }

    function castcase(string $str, int $format = 0): string
    {
        switch ($format) {
            case FORMAT_TITLECASE:
                return mb_ucwords($str);
                break;

            case FORMAT_SENTENCECASE:
                return mb_ucfirst(mb_strtolower($str));
                break;

            case FORMAT_LOWERCASE:
                return mb_strtolower($str);
                break;

            case FORMAT_UPPERCASE:
                return mb_strtoupper($str);
                break;

            default:
            case FORMAT_IDENTITY:
                return $str;
        }
    }


    const ARRAY_TRANSFORMS_OVERRIDE = 1;
    const ARRAY_TRANSFORMS_MERGE = 2;

    /**
     * Transform an array by applying a callback that returns new key-value pairs
     *
     * Advanced array transformation function that supports:
     * - Generator yields for multiple outputs per input
     * - Recursive callbacks via third parameter
     * - Conflict resolution strategies (override vs merge)
     * - Depth tracking for nested transformations
     *
     * The callback receives: ($key, $value, $callback, $counter, $depth)
     * and must return: array|Generator with signature [newKey, newValue] or yield [newKey => newValue]
     *
     * Used extensively in SelectType.php for building form choice hierarchies.
     *
     * @param callable $callback Transformation function. Must declare 'array' or 'Generator' return type.
     * @param array $array The array to transform
     * @param int $depth Current recursion depth (internal use)
     * @param int $prevent_conflicts Strategy: ARRAY_TRANSFORMS_OVERRIDE (default) or ARRAY_TRANSFORMS_MERGE
     * @return array Transformed array
     *
     * @throws Exception If callback lacks proper return type declaration
     */
    function array_transforms(callable $callback, array $array, int $depth = 0, int $prevent_conflicts = ARRAY_TRANSFORMS_OVERRIDE): array
    {
        // Cache ReflectionFunction to avoid recreating for recursive calls with same callback
        static $reflectionCache = [];

        $callbackId = is_object($callback) ? spl_object_id($callback) : md5(serialize($callback));

        if (!isset($reflectionCache[$callbackId])) {
            $reflection = new ReflectionFunction($callback);
            if (!$reflection->getReturnType() || !in_array($reflection->getReturnType()->getName(), ['array', 'Generator'])) {
                throw new Exception('Callable function must use "array" or "Generator" return type');
            }
            $reflectionCache[$callbackId] = true; // Store validation result only
        }

        $tArray = [];
        $counter = 0;
        foreach ($array as $key => $entry) {
            // Call user function with defaults parameters if required
            $ret = call_user_func_with_defaults($callback, $key, $entry, $callback, $counter, $depth);

            // Process generators
            if ($ret instanceof Generator) {
                foreach ($ret as $tKey => $yield) {
                    $tKey = !empty($tKey) ? $tKey : count($tArray);
                    switch ($prevent_conflicts) {
                        case ARRAY_TRANSFORMS_MERGE:
                            $yield ??= [];

                            $tArray[$tKey] ??= [];
                            $tArray[$tKey] = array_merge_recursive2(
                                is_array($tArray[$tKey]) ? $tArray[$tKey] : [$tArray[$tKey]],
                                is_array($yield) ? $yield : [$yield]
                            );

                            break;

                        default:
                        case ARRAY_TRANSFORMS_OVERRIDE:
                            $tArray[$tKey] = $yield;
                    }
                }

                $ret = $ret->getReturn();
            }

            // Process returned value if found
            if ($ret === null) {
                continue;
            }

            list($tKey, $tEntry) = [$ret[0] ?? count($tArray), $ret[1] ?? null];
            switch ($prevent_conflicts) {
                case ARRAY_TRANSFORMS_MERGE:
                    if (!is_array($tEntry)) {
                        $tArray[$tKey] = $tEntry;
                    } else {
                        $tArray[$tKey] ??= [];
                        $tArray[$tKey] = array_merge_recursive(
                            is_array($tArray[$tKey]) ? $tArray[$tKey] : [$tArray[$tKey]],
                            is_array($tEntry) ? $tEntry : [$tEntry]
                        );
                    }

                    break;

                default:
                case ARRAY_TRANSFORMS_OVERRIDE:
                    $tArray[$tKey] = $tEntry;
            }

            $counter++;
        }

        return $tArray;
    }

    /**
     * @param $url
     * @param int $start
     * @param int $end
     * @return string|bool
     */
    function curlranger($url, int $start = 0, int $end = 32768): string|bool
    {
        $headers = ["Range: bytes=" . $start . "-" . $end];

        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);

        $data = curl_exec($curl);
        curl_close($curl);

        return $data;
    }

    function curlimagesize(string $url): ?array
    {
        $raw = curlranger($url);
        if (!$raw) {
            return null;
        }

        try {
            $im = imagecreatefromstring($raw);
        } catch (Exception $e) {
            return null;
        }

        return [imagesx($im), imagesy($im)];
    }

    /**
     * @param array $array
     * @param callable|null $callback
     * @param int $mode
     * @return array
     * @throws Exception
     */
    function array_filter_recursive(array $array, ?callable $callback = null, int $mode = 0)
    {
        return array_transforms(function ($k, $v, $fn) use ($callback, $mode): ?array {
            $v = is_array($v) ? array_transforms($fn, array_filter($v, $callback, $mode)) : $v;
            return $v === [] || $v === null ? null : [$k, $v];
        }, $array);
    }

    /**
     * @param $a
     * @param $b
     * @return float|int
     */
    function mod($a, $b)
    {
        return $a - floor($a / $b) * $b;
    }

    /**
     * @param $a
     * @param $b
     * @return mixed
     */
    function gcd($a, $b)
    {
        return ($a % $b) ? gcd($b, $a % $b) : $b;
    }

    function array_slice_recursive(array $array, int $offset, ?int $length, bool $preserve_keys = false): array
    {
        $offsetCounter = 0;
        $lengthCounter = 0;

        return array_transforms(function ($k, $v, $callback, $i) use ($preserve_keys, &$offsetCounter, $offset, &$lengthCounter, $length): ?array {
            if (is_array($v)) {
                $v = array_transforms($callback, $v);
                return empty($v) ? null : [$preserve_keys ? $k : $i, $v];
            }

            $array = ($offsetCounter < $offset || ($lengthCounter >= $length)) ? null : [$preserve_keys ? $k : $i, $v];
            if ($array !== null) {
                $lengthCounter++;
            }

            $offsetCounter++;
            return $array;
        }, $array);
    }

    const ARRAY_FLATTEN_PRESERVE_KEYS = 1;
    const ARRAY_FLATTEN_PRESERVE_DUPLICATES = 2;
    /**
     * @param string $separator
     * @param array|null $array
     * @param int $limit
     * @return array|null
     * @throws ReflectionException
     */
    function array_key_flattens(string $separator, ?array $array, int $limit = PHP_INT_MAX)
    {
        return array_flatten($separator, $array, $limit, ARRAY_FLATTEN_PRESERVE_KEYS);
    }

    /**
     * @param string $separator
     * @param array|null $array
     * @param int $limit
     * @param int $mode
     * @param callable|null $fn
     * @return array|null
     * @throws ReflectionException
     */
    function array_flatten(string $separator, ?array $array, int $limit = PHP_INT_MAX, int $mode = 0, ?callable $fn = null)
    {
        if ($fn === null) {
            $fn = function ($k, $v): bool {
                return is_array($v);
            };
        }

        $reflection = new ReflectionFunction($fn);
        if (!$reflection->getReturnType() || $reflection->getReturnType()->getName() != 'bool') {
            throw new Exception('Callable function must use "bool" return type');
        }

        $ret = [];
        if (!is_array($array)) {
            $array = func_get_args();
        }

        if (!$limit) {
            return $array;
        }
        foreach ($array as $key => $value) {
            switch ($mode) {
                default:
                case ARRAY_FLATTEN_PRESERVE_KEYS:
                    $flattenValues = is_array($value) && $fn($key, $value) ? array_flatten($separator, $value, $limit == PHP_INT_MAX ? PHP_INT_MAX : --$limit, $mode, $fn) : $value;

                    if (is_array($value) && $fn($key, $flattenValues)) {
                        foreach ($flattenValues as $key2 => $flattenValue) {
                            $ret[$key . $separator . $key2] = $flattenValue;
                        }
                    } else {
                        $ret[$key] = $flattenValues;
                    }

                    break;

                case ARRAY_FLATTEN_PRESERVE_DUPLICATES:
                    $flattenValues = $fn($key, $value) ? array_flatten($separator, $value, $limit == PHP_INT_MAX ? PHP_INT_MAX : --$limit, $mode, $fn) : [$key => $value];
                    foreach ($flattenValues as $key2 => $flattenValue) {
                        if (!array_key_exists($key2, $ret)) {
                            $ret[$key . $separator . $key2] = [];
                        }

                        $ret[$key . $separator . $key2][] = $flattenValue;
                    }
                    break;
            }
        }

        return $ret;
    }

    const ARRAY_INFLATE_INCREMENT_INTKEYS = 1;
    /**
     * @param string $separator
     * @param array|null $array
     * @param int $mode
     * @param int $limit
     * @return array|null
     */
    function array_inflate(string $separator, ?array $array, int $mode = 0, int $limit = PHP_INT_MAX)
    {
        if (!is_array($array)) {
            return null;
        }

        $ret = [];
        foreach ($array as $key => $value) {
            $keys = explode($separator, $key, $limit);
            list($head, $tail) = [head($keys), implode($separator, tail($keys))];
            $ret[$head] ??= [];

            $limit = ($limit == PHP_INT_MAX) ? PHP_INT_MAX : $limit - count($keys);
            if ($tail !== "") {
                $ret[$head] = match ($mode) {
                    ARRAY_INFLATE_INCREMENT_INTKEYS => array_merge_recursive($ret[$head], array_inflate($separator, [$tail => $value], $mode, $limit)),
                    default => array_merge_recursive2($ret[$head], array_inflate($separator, [$tail => $value], $mode, $limit)),
                };
            } else {
                if (is_array($value)) {
                    $ret[$head] = array_inflate($separator, $value, $mode, $limit);
                } elseif (!empty($ret[$head])) {
                    $ret[$head][] = $value;
                } else {
                    $ret[$head] = $value;
                }
            }
        }

        return $ret;
    }

    /**
     * @param array ...$arrays
     * @return array|mixed
     */
    function array_merge_recursive2(array ...$arrays)
    {
        $ret = head($arrays);
        foreach (tail($arrays) as $_) {
            foreach ($_ as $key => $value) {
                if (!array_key_exists($key, $ret)) {
                    $ret[$key] = $value;
                } else {
                    if (is_array($value)) {
                        $ret[$key] = array_merge_recursive2($ret[$key], $value);
                    } else {
                        if (!empty($ret[$key])) {
                            if (is_array($ret[$key])) {
                                $ret[$key][] = $value;
                            } else {
                                $ret[$key] = [$ret[$key]];
                                $ret[$key][] = $value;
                            }
                        } else {
                            $ret[$key] = $value;
                        }
                    }
                }
            }
        }

        return $ret;
    }

    /**
     * @param $objectOrClass
     * @param array $haystack
     * @return string|int|false
     */
    function array_class($objectOrClass, array $haystack): string|int|false
    {
        $className = is_object($objectOrClass) ? get_class($objectOrClass) : $objectOrClass;
        foreach ($haystack as $key => $item) {
            if ($item instanceof $className) {
                return $key;
            }
        }

        return false;
    }

    /**
     * @param array $haystack
     * @return string|null
     */
    function array_class_ancestor(array $haystack): ?string
    {
        if (empty($haystack)) {
            return null;
        }

        // Normalize to class names
        $classes = array_map(
            fn($c) => is_object($c) ? get_class($c) : $c,
            $haystack
        );

        // Get ancestors of first class (including itself)
        $common = array_merge(
            [$classes[0]],
            class_parents($classes[0]) ?: []
        );

        // Intersect with ancestors of all other classes
        foreach (array_slice($classes, 1) as $class) {
            $ancestors = array_merge(
                [$class],
                class_parents($class) ?: []
            );

            $common = array_intersect($common, $ancestors);

            if (empty($common)) {
                return null;
            }
        }

        // Return nearest ancestor (child-most)
        return array_values($common)[0];
    }

    /**
     * @param $objectOrClass
     * @param array $haystack
     * @return string|int|false
     */
    function array_class_last($objectOrClass, array $haystack): string|int|false
    {
        $haystack = array_reverse($haystack);
        if (is_associative($haystack)) {
            return array_class($objectOrClass, $haystack);
        }

        $position = array_class($objectOrClass, $haystack);
        if ($position === false) {
            return false;
        }

        return count($haystack) - $position - 1;
    }

    function array_search_last(mixed $needle, array $haystack, bool $strict = false): string|int|false
    {
        $haystack = array_reverse($haystack);
        if (is_associative($haystack)) {
            return array_search($needle, $haystack, $strict);
        }

        $position = array_search($needle, $haystack, $strict);
        if ($position === false) {
            return false;
        }

        return count($haystack) - $position - 1;
    }

    function array_search_recursive(mixed $needle, array $haystack): array|false
    {
        foreach ($haystack as $key => $value) {
            if ($value === $needle) {
                return [$key];
            }
            if (is_array($value) && ($current = array_search_recursive($needle, $value))) {
                return array_merge([$key], $current);
            }
        }

        return false;
    }

    function basename_namespace(string $namespace)
    {
        $array = explode("\\", $namespace);
        return last($array);
    }
    
    function dirname_namespace(string $namespace, int $level = 1)
    {
        $array = explode("\\", $namespace);
        while($level-- > 0) array_pop($array);

        return implode("\\", $array);
    }
    
    function array_pop_key(mixed $key, array &$array): mixed
    {
        if (empty($array)) {
            return null;
        }

        if(is_array($key)) {
            $result = [];
            foreach ($key as $k) {
                $result[$k] = array_pop_key($k, $array);
            }
            return $result;
        }

        $entry = $array[$key] ?? null;
        $array = array_key_removes($array, $key);

        return $entry;
    }

    function array_pop_keys(array $keys, array &$array): array
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = array_pop_key($key, $array);
        }
        return $result;
    }

    function array_pop_class(string $class, array &$array): mixed
    {
        if (empty($array)) {
            return null;
        }

        foreach ($array as $key => $entry) {
            if ($entry instanceof $class) {
                return array_pop_key($key, $array);
            }
        }

        return null;
    }
    
    function array_key_startsWith(array $array, string $needle): array
    {
        return array_transforms(fn($k, $v): ?array => str_starts_with($k, $needle) ? [$k, $v] : null, $array);
    }

    function array_key_endsWith(array $array, string $needle): array
    {
        return array_transforms(fn($k, $v): ?array => str_ends_with($k, $needle) ? [$k, $v] : null, $array);
    }

    function array_keys_recursive(array $array): array
    {
        $keys = [];

        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $keys[$key] = array_keys_recursive($value);
            } else {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * @param array $array
     * @param $value
     * @param int $limit
     * @return void
     */
    function array_occurrence_removes(array &$array, $value, int $limit = 1)
    {
        // $array was by value, so array_splice mutated a discarded copy and the
        // caller's array was never touched (same class as the old usort_column).
        while ($limit-- > 0 && ($pos = array_search($value, $array)) !== false) {
            array_splice($array, $pos, 1);
        }
    }

    /**
     * @param $array
     * @param $fn
     * @return false|int|string
     */
    function array_search_user($array, $fn)
    {
        foreach ($array as $key => $entry) {
            if (call_user_func($fn, $key, $entry) === true) {
                return $key;
            }
        }

        return false;
    }

    function array_key_keeps(array $array, string ...$keys): array
    {
        $keys = array_diff(array_keys($array), $keys);
        foreach ($keys as $key) {
            unset($array[$key]);
        }

        return $array;
    }

    function array_column_object($array, $key) {
        return array_map(function($e) use ($key) {
            return is_object($e) ? $e->$key : $e[$key];
        }, $array);
    }

    /**
     * @param mixed $needle
     * @param array $haystack
     * @return bool
     */
    function in_array_object(mixed $needle, array $haystack)
    {
        if (!is_object($needle)) {
            return false;
        }

        foreach ($haystack as $entry) {
            if (is_object($entry) && strcmp(spl_object_hash($entry), spl_object_hash($needle)) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array $array
     * @param ...$rest
     * @return array
     */
    function array_diff_object(array $array, ...$rest)
    {
        $rest[] = fn($a, $b) => strcmp(spl_object_hash($a), spl_object_hash($b));
        return array_udiff($array, ...$rest);
    }

    function array_key_removes(array $array, string ...$keys): array
    {
        foreach ($keys as $key) {
            unset($array[$key]);
        }

        return $array;
    }

    /**
     * @param array $array
     * @param bool $recursive
     * @return array
     */
    function array_key_removes_numerics(array $array, bool $recursive = true)
    {
        $arrayOut = [];
        foreach ($array as $k => $v) {
            if (is_numeric($k)) {
                continue;
            } elseif ($recursive && is_array($v)) {
                $arrayOut[$k] = array_key_removes_numerics($v, $recursive);
            } else {
                $arrayOut[$k] = $v;
            }
        }

        return $arrayOut;
    }

    /**
     * @param array $array
     * @param bool $recursive
     * @return array
     */
    function array_key_removes_string(array $array, bool $recursive = true)
    {
        $arrayOut = [];
        foreach ($array as $k => $v) {
            if (is_string($k)) {
                continue;
            } elseif ($recursive && is_array($v)) {
                $arrayOut[$k] = array_key_removes_string($v, $recursive);
            } else {
                $arrayOut[$k] = $v;
            }
        }

        return $arrayOut;
    }

    /**
     * @param array $array
     * @param bool $recursive
     * @param ...$needles
     * @return array
     */
    function array_key_removes_startsWith(array $array, bool $recursive = true, ...$needles)
    {
        $arrayOut = [];
        foreach ($array as $k => $v) {
            // Remove the key when the KEY starts with one of the needles.
            // The args were swapped (str_starts_with($needle, $k)), which
            // asked whether the needle started with the key — backwards, so
            // e.g. key "filters[state]" was never stripped by needle "filters[".
            // (cf. the correct array_key_startsWith.)
            if (array_filter($needles, fn($needle) => str_starts_with((string) $k, (string) $needle))) {
                continue;
            } elseif ($recursive && is_array($v)) {
                $arrayOut[$k] = array_key_removes_startsWith($v, $recursive, ...$needles);
            } else {
                $arrayOut[$k] = $v;
            }
        }

        return $arrayOut;
    }

    /**
     * @param array $array
     * @param bool $recursive
     * @param ...$needles
     * @return array
     */
    function array_key_removes_endsWith(array $array, bool $recursive = true, ...$needles)
    {
        $arrayOut = [];
        foreach ($array as $k => $v) {
            // Remove the key when the KEY ends with one of the needles (args
            // were swapped, same bug as array_key_removes_startsWith above).
            if (array_filter($needles, fn($needle) => str_ends_with((string) $k, (string) $needle))) {
                continue;
            } elseif ($recursive && is_array($v)) {
                $arrayOut[$k] = array_key_removes_endsWith($v, $recursive, ...$needles);
            } else {
                $arrayOut[$k] = $v;
            }
        }

        return $arrayOut;
    }

    /**
     * @param array|string $separator
     * @param array $array
     * @param int $limit
     * @return array
     * @throws Exception
     */
    function array_key_explodes(array|string $separator, array $array, int $limit = PHP_INT_MAX)
    {
        return array_transforms(function ($k, $v, $callback, $_, $depth) use ($separator, $limit): array {
            if ($limit >= 0 && $depth >= $limit) {
                return [$k, $v];
            }

            $subk = explodeByArray($separator, $k);
            return [head($subk), count($subk) > 1 ? array_transforms($callback, [implode(".", tail($subk)) => $v], ++$depth, ARRAY_TRANSFORMS_MERGE) : $v];
        }, $array, 0, ARRAY_TRANSFORMS_MERGE);
    }

    function array_values_keep(array $array, string ...$values): array
    {
        foreach ($array as $key => $value) {
            if (!in_array($value, $values)) {
                unset($array[$key]);
            }
        }

        return $array;
    }

    function array_values_remove(array $array, ...$values): array
    {
        return array_filter($array, fn($v) => !in_array($v, $values));
    }

    function array_values_insert(array $array, ...$values): array
    {
        foreach ($values as $value) {
            if (!in_array($value, $array)) {
                $array[] = $value;
            }
        }

        return $array;
    }

    function array_values_insert_any(array $array, ...$values): array
    {
        foreach ($values as $value) {
            $array[] = $value;
        }

        return $array;
    }

    function array_union(...$arrays): array
    {
        $union = [];
        foreach ($arrays as $array) {
            $union += $array;
        }

        return $union;
    }
    
    function mailparse(string $addresses): array
    {
        $regex = '/(?:\w*:)*\s*(?:"([^"]*)"|([^,;\/""<>]*))?\s*(?:(?:[,;\/]|<|\s+|^)([^<@\s;,]+@[^>@\s,;\/]+)>?)\s*/';
        if (preg_match_all($regex, $addresses, $matches, PREG_SET_ORDER) > 0) {
            $matches = array_transforms(fn($k, $x): array => [trim($x[3]), empty(trim($x[1] . $x[2])) ? trim($x[3]) : trim($x[1] . $x[2])], $matches);
        }

        return $matches;
    }

    function mailformat(array $address, ?string $name = null, ?string $email = null): string
    {
        $email ??= array_keys($address)[0] ?? null;
        if (!$email) {
            return "";
        }

        $name ??= first($address);
        $name = $name ?: array_keys($address)[0] ?? $email;

        $name = str_replace("@", "[at]", $name);
        return $name . " <" . $email . ">";
    }

    function str_replace_prefix(string $search, string $replace, string $subject): string
    {
        if (!str_starts_with($subject, $search)) {
            return $subject;
        }

        // Only the leading occurrence. str_replace's 4th arg is a by-ref output
        // count, NOT a replacement limit — passing $count=1 replaced EVERY
        // occurrence, not just the prefix.
        return $replace . substr($subject, strlen($search));
    }

    function str_replace_suffix(string $search, string $replace, string $subject): string
    {
        if (!str_ends_with($subject, $search)) {
            return $subject;
        }

        // Only the trailing occurrence — same str_replace $count misuse as above.
        return substr($subject, 0, strlen($subject) - strlen($search)) . $replace;
    }

    /**
     * @param $array
     * @return array
     * @throws Exception
     */
    function array_unique_end($array)
    {
        $len = count($array);
        return array_transforms(fn($k, $v): array => [$len - $k - 1, $v], array_unique(array_reverse($array)));
    }

    function array_unique_map(callable $callback, array $array, int $flags = SORT_STRING): array
    {
        $arrayMask = array_fill_keys(array_keys(array_unique(array_map($callback, $array), $flags)), null);
        return array_intersect_key($array, $arrayMask);
    }

    /**
     * @param object $object
     * @param array|object|null $vars
     * @return object
     * @throws Exception
     */
    function object_init(object $object, array $ignore_vars = [])
    {
        $reflClass = new ReflectionClass($object);

        do {

            foreach ($reflClass->getProperties() as $reflProperty) {

                if(in_array($reflProperty->getName(), $ignore_vars) ) continue;
                if($reflProperty->isInitialized($object)) continue;

                $defaultValue = $reflProperty->getDefaultValue();
                if($defaultValue !== null || $reflProperty->getType()->allowsNull()) {
                    $reflProperty->setValue($object, $defaultValue);
                    continue;
                }

                if($reflProperty->getType()->isBuiltin()) {

                    $defaultBuiltin = match ($reflProperty->getType()->getName())
                    {
                        "array" => [],
                        "callable" => function() {},
                        "bool" => false,
                        "float", "int" => 0,
                        "string" => "",
                        default => null
                    };

                    if($defaultBuiltin !== null) {
                        $reflProperty->setValue($object, $defaultBuiltin);
                        continue;
                    }
                }
            }

        } while ($reflClass = $reflClass->getParentClass());

        return $object;
    }

    /**
     * @param object $object
     * @return array
     * @throws Exception
     */
    function object_dehydrate(object $object, bool $keepChildObjects = true): array
    {
        return cast_to_array($object, $keepChildObjects);
    }

    /**
     * @param object $object
     * @param array|object|null $vars
     * @return object
     * @throws Exception
     */
    function object_hydrate(object $object, array|object|null $vars = null)
    {
        if ($vars === null) {
            return $object;
        }

        if (is_object($vars)) {
            $vars = cast_to_array($vars);
        }

        $reflClass = new ReflectionClass($object);

        do {
            foreach ($reflClass->getProperties() as $reflProperty) {
                $value = $vars[$reflProperty->getName()] ?? null;
                if (!$value) {
                    continue;
                }

                // Doctrine keeps an enum field's original data as its backing
                // value ("draft", not Status::Draft): the snapshot
                // AbstractAttribute::getOldEntity() rebuilds from it - for
                // every entity carrying an #[Uploader] - put that string into
                // an enum-typed property, a TypeError. A value no case
                // matches is left out of the snapshot rather than fatal.
                $value = property_enum_value($reflProperty, $value);
                if ($value === null) {
                    continue;
                }

                $reflProperty->setAccessible(true);
                try {
                    $reflProperty->setValue($object, $value);
                } catch (TypeError) {
                    // Data the property cannot hold is left out, not fatal.
                    continue;
                }
            }

        } while ($reflClass = $reflClass->getParentClass());

        return $object;
    }

    /**
     * $value as the property can hold it when its type is an enum: a backing
     * value (int or string) becomes BackedEnum::tryFrom($value), a case name
     * becomes that case of a pure enum. Null when the property only takes an
     * enum and no case matches. Any other value, or a property whose type
     * accepts the value as it is (string, mixed, untyped), is returned
     * unchanged.
     */
    function property_enum_value(ReflectionProperty $reflProperty, mixed $value): mixed
    {
        if (!is_int($value) && !is_string($value)) {
            return $value;
        }

        $type = $reflProperty->getType();
        $types = match (true) {
            $type instanceof ReflectionNamedType => [$type],
            $type instanceof ReflectionUnionType => $type->getTypes(),
            default => [],
        };

        $enums = [];
        foreach ($types as $namedType) {
            if (!$namedType instanceof ReflectionNamedType) {
                return $value; // an intersection inside a union: leave it to PHP
            }

            $name = $namedType->getName();
            if ($namedType->isBuiltin()) {
                if (in_array($name, ["mixed", get_debug_type($value)], true)) {
                    return $value;
                }
                continue;
            }

            if (enum_exists($name)) {
                $enums[] = $name;
            }
        }

        if (!$enums) {
            return $value;
        }

        foreach ($enums as $enum) {
            if (is_subclass_of($enum, BackedEnum::class)) {
                $backing = (string) (new ReflectionEnum($enum))->getBackingType();
                if ($backing === "int" && is_string($value) && !preg_match('/^-?\d+$/', $value)) {
                    continue;
                }

                $case = $enum::tryFrom($backing === "int" ? (int) $value : (string) $value);
                if ($case !== null) {
                    return $case;
                }
            } elseif (is_string($value) && defined($enum . "::" . $value) && constant($enum . "::" . $value) instanceof $enum) {
                return constant($enum . "::" . $value);
            }
        }

        return null;
    }

    /**
     * @param object $object
     * @return array
     * @throws Exception
     */
    function cast_to_array(object $object, bool $keepChildObjects = true)
    {
        return array_transforms(function($k, $v) use ($keepChildObjects): ?array {
            
            $k = str_lstrip($k, ["+", "*"]);
            $k = explode("\x00", $k);
            $k = last($k);

            if(!$keepChildObjects && is_object($v)) return null;
            return [$k, $v];
        
        }, (array) $object);
    }

    /**
     * @param array $array
     * @param string $newClass
     * @return mixed
     */
    function cast_from_array(array $array, string $newClass)
    {
        return unserialize(str_replace('O:8:"stdClass"', 'O:' . strlen($newClass) . ':"' . $newClass . '"', serialize((object)$array)));
    }

    /**
     * @param string $newClass
     * @return mixed
     */
    function cast_empty(string $newClass)
    {
        return unserialize(str_replace('O:8:"stdClass"', 'O:' . strlen($newClass) . ':"' . $newClass . '"', serialize((object)[])));
    }

    /**
     * @param $object
     * @param $newClass
     * @param ...$args
     * @return mixed|object|null
     * @throws ReflectionException
     */
    function cast($object, $newClass, ...$args)
    {
        if ($object == null) {
            return null;
        }

        $reflClass = new ReflectionClass($object);
        $reflProperties = $reflClass->getProperties();

        $reflNewClass = new ReflectionClass($newClass);
        $reflConstr = new ReflectionMethod($newClass, '__construct');

        if ($reflConstr->getNumberOfParameters() && !count($args)) {
            $newObject = $reflNewClass->newInstanceWithoutConstructor();
        } else {
            $newObject = new $newClass(...$args);
        }

        do {
            foreach ($reflNewClass->getProperties() as $reflNewProperty) {
                $reflNewProperty->setAccessible(true);

                $reflProperty = array_filter($reflProperties, fn($p) => $p->getName() == $reflNewProperty->getName());
                $reflProperty = begin($reflProperty) ?? null;
                if ($reflProperty) {
                    $reflProperty->setAccessible(true);

                    $value = $reflProperty->getValue($object);
                    $reflNewProperty->setValue($newObject, $value);
                }
            }
        } while ($reflNewClass = $reflNewClass->getParentClass());

        return $newObject;
    }

    /**
     * @param $str
     * @return bool
     */
    function is_serialized($str): bool
    {
        if (!is_string($str)) {
            return false;
        }

        $str = trim($str);

        // Detect a PHP serialize() string. The old body required the value to
        // start with "{" and end with "}" (a JSON-object shape), which is NEVER
        // true of real serialize() output (a:…, s:…, O:…, i:…, b:…, N;), so it
        // rejected everything genuinely serialized — its own `$str == 'b:0;'`
        // special-case was unreachable behind those guards.
        if ('N;' === $str || 'b:0;' === $str) {   // serialized null / false
            return true;
        }
        if (strlen($str) < 4 || ':' !== ($str[1] ?? '')) {
            return false;
        }

        // Validate by unserializing WITHOUT instantiating any objects, so the
        // detection itself carries no object-injection side effect (an actual
        // serialized object still reports true — it just resolves to an
        // __PHP_Incomplete_Class placeholder here, never a real instance).
        return @unserialize($str, ['allowed_classes' => false]) !== false;
    }

    /**
     * @param $object
     * @return bool
     */
    function is_serializable($object): bool
    {
        try {
            serialize($object);
        } catch (Exception $e) {
            return false;
        }

        return true;
    }

    /**
     * @param $str
     * @return string
     */
    function str_strip_accents($str)
    {
        return strtr($str, 'àáâãäçèéêëìíîïñòóôõöùúûüýÿÀÁÂÃÄÇÈÉÊËÌÍÎÏÑÒÓÔÕÖÙÚÛÜÝ', 'aaaaaceeeeiiiinooooouuuuyyAAAAACEEEEIIIINOOOOOUUUUY');
    }

    function abc2dec(string $s): int
    {
        $abc = array_flip(range('a', 'z'));
        $dec = "";

        foreach (str_split(strtolower($s)) as $c) {
            $dec .= $abc[$c];
        }

        return intval($dec);
    }

    function dec2abc(int $dec): string
    {
        // Inverse of abc2dec(), which maps each letter to its 0-based position
        // (a=0…z=25) and concatenates. Map each decimal digit back to a letter
        // (0→a, 1→b, …). The old code declared `: int` (returning a string
        // TypeError'd) and did chr($digitChar), which is not that inverse.
        $abc = "";
        foreach (str_split(strval($dec)) as $c) {
            $abc .= chr(ord('a') + intval($c));
        }

        return $abc;
    }

    /**
     * @param string $s
     * @return string
     */
    function hexv2abc(string $s)
    {
        return strtr(strtoupper(base_convert($s, 10, 26)), "0123456789ABCDEFGHIJKLMNOP", "ABCDEFGHIJKLMNOPQRSTUVWXYZ");
    }

    /**
     * @param string $s
     * @return string
     */
    function abc2hexv(string $s)
    {
        return base_convert(strtr(strtoupper($s), "0123456789ABCDEFGHIJKLMNOP", "ABCDEFGHIJKLMNOPQRSTUVWXYZ"), 10, 26);
    }

    function hex2rgba(string $hex): array
    {
        return sscanf(strtoupper($hex), "#%02x%02x%02x%02x");
    }

    function hex2rgb(string $hex): array
    {
        return sscanf(strtoupper($hex), "#%02x%02x%02x");
    }

    function hex2hsl(string $hex): array
    {
        return rgb2hsl(hex2rgb($hex));
    }

    function hex2int(string $hex): int
    {
        return hexdec(ltrim($hex, '#'));
    }

    function hex2alpha(string $hex): float
    {
        return hexdec(ltrim($hex, '#')) / 0xFF;
    }

    function alpha2hex(float $alpha, bool $hash = true): string
    {
        // $alpha (0..1) → a 2-digit hex byte. The old `$alpha * 0xFF`
        // concatenated a raw, un-rounded decimal ("#255", "#127.5") instead of
        // a hex pair (cf. int2hex's sprintf('%06X', ...)).
        return ($hash ? '#' : '') . sprintf('%02X', (int) round(max(0.0, min(1.0, $alpha)) * 0xFF));
    }

    function int2hex(int $int, bool $hash = true): string
    {
        return ($hash ? '#' : '') . sprintf('%06X', $int);
    }

    function int2rgb(int $int): array
    {
        return [$int >> 16 & 0xFF, $int >> 8 & 0xFF, $int & 0xFF];
    }

    function float2rgba(float $float): array
    {
        return [$float >> 24 & 0xFF, $float >> 16 & 0xFF, $float >> 8 & 0xFF, $float & 0xFF];
    }

    /**
     * @param array $rgb
     * @return float|int|mixed
     */
    function rgb2int(array $rgb)
    {
        return ($rgb[0] * 65536) + ($rgb[1] * 256) + ($rgb[2]);
    }

    function rgb2hex(array $rgb): string
    {
        return sprintf("#%02X%02X%02X", ...array_pad($rgb, 3, 0));
    }

    function rgba2float(array $rgba): float
    {
        return ($rgba[0] * 4294967296) + ($rgba[1] * 65536) + ($rgba[2] * 256) + ($rgba[3]);
    }

    function rgba2hex(array $rgba): string
    {
        return sprintf("#%02X%02X%02X%02X", ...array_pad($rgba, 4, 0));
    }

    /**
     * @param int $length
     * @return string
     */
    function str_blankspace(int $length)
    {
        return $length < 1 ? "" : str_repeat(" ", $length);
    }

    function usort_column(array &$array, string $column, callable $fn): bool
    {
        return usort($array, fn($a1, $a2) => $fn($a1[$column] ?? null, $a2[$column] ?? null));
    }

    function usort_key(array $array, array $ordering): array
    {
        // build list of indices for $current
        $indices = array_keys($array);

        // stable sort indices by ordering value (missing => very large => goes last)
        usort($indices, function($i, $j) use ($ordering) {
            $pi = $ordering[$i] ?? PHP_INT_MAX;
            $pj = $ordering[$j] ?? PHP_INT_MAX;
            return $pi <=> $pj;
        });

        // return values in new order, reindexed 0..N-1
        $result = [];
        foreach ($indices as $idx) {
            $result[] = $array[$idx];
        }
        
        return $result;
    }

    /**
     * @param array $array
     * @param string|array $startingWith
     * @return true
     */
    function usort_startsWith(array &$array, string|array $startingWith)
    {
        if (!is_array($startingWith)) {
            $startingWith = [$startingWith];
        }

        return usort($array, function ($a1, $a2) use ($startingWith) {
            foreach ($startingWith as $needle) {
                if (str_starts_with($a1, $needle) && str_starts_with($a2, $needle)) {
                    return 0;
                }
                if (!str_starts_with($a1, $needle) && str_starts_with($a2, $needle)) {
                    return 1;
                }
                if (str_starts_with($a1, $needle) && !str_starts_with($a2, $needle)) {
                    return -1;
                }
            }

            return 0;
        });
    }

    /**
     * @param array $array
     * @param string|array $startingWith
     * @return true
     */
    function usort_endsWith(array &$array, string|array $startingWith)
    {
        // by-value → the usort() sorted a discarded copy (same class as the old
        // usort_column); the caller's array was never reordered.
        if (!is_array($startingWith)) {
            $startingWith = [$startingWith];
        }

        return usort($array, function ($a1, $a2) use ($startingWith) {
            foreach ($startingWith as $needle) {
                if (str_ends_with($a1, $needle) && str_ends_with($a2, $needle)) {
                    return 0;
                }
                if (str_ends_with($a1, $needle) && !str_ends_with($a2, $needle)) {
                    return 1;
                }
                if (!str_ends_with($a1, $needle) && str_ends_with($a2, $needle)) {
                    return -1;
                }
            }

            return 0;
        });
    }

    /**
     * @param array $array
     * @param $a
     * @param $b
     * @return void
     */
    function array_swap(array &$array, $a, $b)
    {
        if (array_key_exists($a, $array) && array_key_exists($b, $array)) {
            [$array[$a], $array[$b]] = [$array[$b], $array[$a]];
        }
    }

    /**
     * @param array $array
     * @param array $mask
     * @return array
     */
    function array_reverseByMask(array $array, array $mask)
    {
        $mask = array_pad($mask, count($array), false);

        $keys = [];
        foreach ($mask as $key => $b) {
            if ($b) {
                $keys[] = $key;
            }
        }

        return array_reverseByKey($array, $keys);
    }

    /**
     * @param array $array
     * @param array $keys
     * @return array
     */
    function array_reverseByKey(array $array, array $keys)
    {
        if (count($keys) == 1) {
            return $array;
        }

        $N = ceil(count($keys) / 2);
        for ($i = 0; $i < $N; $i++) {
            array_swap($array, $keys[$i], $keys[count($keys) - $i - 1]);
        }

        return $array;
    }

    function hsl2hex(array $hsl): string
    {
        return rgb2hex(hsl2rgb($hsl));
    }

    function rgb2hsl(array $rgb): array
    {
        list($r, $g, $b) = $rgb;
        $r /= 255;
        $g /= 255;
        $b /= 255;

        $min = min($r, $g, $b);
        $max = max($r, $g, $b);
        $delta = $max - $min;

        $s = 0;
        $l = ($min + $max) / 2;
        if ($l > 0 && $l < 1) {
            $s = $delta / ($l < 0.5 ? (2 * $l) : (2 - 2 * $l));
        }

        $h = 0;
        if ($delta > 0) {
            if ($max == $r && $max != $g) {
                $h += ($g - $b) / $delta;
            }
            if ($max == $g && $max != $b) {
                $h += (2 + ($b - $r) / $delta);
            }
            if ($max == $b && $max != $r) {
                $h += (4 + ($r - $g) / $delta);
            }
            $h /= 6;
        }

        return [round($h, 5), round($s, 5), round($l, 5)];
    }

    function hsl2rgb(array $hsl): array
    {
        list($h, $s, $l) = $hsl;
        $m2 = ($l <= 0.5) ? $l * ($s + 1) : $l + $s - $l * $s;
        $m1 = $l * 2 - $m2;
        return [
            0 => intval(round(hue2rgb($m1, $m2, $h + 0.33333) * 255)),
            1 => intval(round(hue2rgb($m1, $m2, $h) * 255)),
            2 => intval(round(hue2rgb($m1, $m2, $h - 0.33333) * 255)),
        ];
    }

    /**
     * @param $m1
     * @param $m2
     * @param $h
     * @return float|int|mixed
     */
    function hue2rgb($m1, $m2, $h)
    {
        $h = ($h < 0) ? $h + 1 : (($h > 1) ? $h - 1 : $h);
        if ($h * 6 < 1) {
            return $m1 + ($m2 - $m1) * $h * 6;
        }
        if ($h * 2 < 1) {
            return $m2;
        }
        if ($h * 3 < 2) {
            return $m1 + ($m2 - $m1) * (0.66666 - $h) * 6;
        }

        return $m1;
    }

    /**
     * @param callable $callback
     * @param $value
     * @return array
     */
    function apply_callback(callable $callback, $value)
    {
        if (is_array($value)) {
            $array = [];
            foreach ($value as $v) {
                $array[] = $callback($v);
            }

            return $array;
        }

        return $callback($value);
    }

    /**
     * @param array $array
     * @param array $reference
     * @return array
     * @throws Exception
     */
    function array_order(array $array, array $reference)
    {
        if (!array_every($array, ...$reference) || count($array) != count($reference)) {
            throw new Exception("Bijective array requested.");
        }

        $order = [];
        foreach ($reference as $value) {
            $order[] = array_search($value, $array);
            $array = array_key_removes($array, end($order));
        }

        return array_merge(array_values($array), $order);
    }

    /**
     * @param array|null $array
     * @return bool
     */
    function is_identity(?array $array)
    {
        foreach ($array ?? [] as $key => $value) {
            // A sub-array must itself be an identity; a scalar must equal its
            // key. The old `is_array($value) && !is_identity($value)` collapsed
            // to false for an identity sub-array and then fell into the
            // `elseif ($key !== $value)` which compares an int key to an array
            // — always true — so any array containing a sub-array was never
            // considered an identity.
            if (is_array($value)) {
                if (!is_identity($value)) {
                    return false;
                }
            } elseif ($key !== $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param Closure $c
     * @return string
     * @throws ReflectionException
     */
    function dump_closure(Closure $c)
    {
        $str = 'function (';
        $r = new ReflectionFunction($c);
        $params = array();
        foreach ($r->getParameters() as $p) {
            $s = '';
            if ($p->isArray()) {
                $s .= 'array ';
            } elseif ($p->getClass()) {
                $s .= $p->getClass()->name . ' ';
            }
            if ($p->isPassedByReference()) {
                $s .= '&';
            }
            $s .= '$' . $p->name;
            if ($p->isOptional()) {
                $s .= ' = ' . var_export($p->getDefaultValue(), true);
            }
            $params [] = $s;
        }
        $str .= implode(', ', $params);
        $str .= '){' . PHP_EOL;
        $lines = file($r->getFileName());

        for ($l = $r->getStartLine(); $l < $r->getEndLine(); $l++) {
            $str .= $lines[$l];
        }
        return $str;
    }

    /**
     * @param string|int|DateTime|null $datetime
     * @return DateTime
     */
    function cast_datetime(null|string|int|DateTime $datetime)
    {
        if ($datetime === null) {
            return null;
        }
        return is_int($datetime) ? (new DateTime())->setTimestamp($datetime) :
            (is_string($datetime) ? (new DateTime())->modify($datetime) : (clone $datetime));
    }

    function daydiff(null|string|int|DateTime $datetime): ?int
    {
        if ($datetime == null) {
            return null;
        }

        $datetime = cast_datetime($datetime);
        $today = new DateTime("today");
        $diff = $today->diff($datetime->setTime(0, 0));
        return (int)$diff->format("%R%a");
    }

    function ceil_datetime(null|string|int|DateTime $datetime, null|string|int $precision): DateTime
    {
        $datetime = cast_datetime($datetime);
        $precision = cast_datetime($precision);

        $timestamp = $datetime->format("U");
        $modulo = abs($precision->format("U") - (new DateTime())->format("U"));
        $delta = $timestamp == $modulo ? 0 : $timestamp % $modulo;

        return $datetime->setTimestamp($timestamp - $delta + $modulo);
    }

    function floor_datetime(null|string|int|DateTime $datetime, null|string|int $precision): DateTime
    {
        $datetime = cast_datetime($datetime);
        $precision = cast_datetime($precision);

        $timestamp = $datetime->format("U");
        $modulo = abs($precision->format("U") - (new DateTime())->format("U"));
        $delta = $timestamp == $modulo ? 0 : $timestamp % $modulo;

        return $datetime->setTimestamp($timestamp - $delta);
    }

    function is_length_safe(?string $directory): bool
    {
        if(!$directory) return true;
        $maxPathLength = constant("PHP_MAXPATHLEN");
        return strlen($directory) <= $maxPathLength;
    }

    /**
     * @param string $directory
     * @param int $permissions
     * @param bool $recursive
     * @param $context
     * @return bool
     */
    function mkdir_length_safe(string $directory, int $permissions = 0777, bool $recursive = true, $context = null): bool
    {
        if (is_length_safe($directory)) {
            return mkdir($directory, $permissions, $recursive, $context);
        }

        $ls = getcwd();
        $directory = str_lstrip($directory, $ls);
        if (str_starts_with($directory, "/")) {
            chdir("/");
        }

        $directories = explode("/", $directory);
        foreach ($directories as $directory) {
            if (!$directory) {
                continue;
            }
            if (!is_length_safe($directory)) {
                //throw new LogicException("Directory name is too long. (PHP_MAXPATHLEN = ".constant("PHP_MAXPATHLEN").")");
                return false;
            }

            if (!file_exists($directory)) {
                mkdir($directory, $permissions, $recursive, $context);
            }
            if (!is_dir($directory)) {
                throw new LogicException("\"" . getcwd() . "/" . $directory . "\" is a file.");
            }

            chdir($directory);
        }

        chdir($ls);
        return true;
    }

    function round_datetime(null|string|int|DateTime $datetime, null|string|int $precision): DateTime
    {
        $datetime = cast_datetime($datetime);
        $precision = cast_datetime($precision);

        $timestamp = $datetime->format("U");
        $modulo = abs($precision->format("U") - time());
        $delta = $timestamp % $modulo;

        return $datetime->setTimestamp($timestamp - $delta + ($delta < $modulo / 2 ? 0 : $modulo));
    }

    /**
     * @param string|int|DateTime|null $datetime
     * @param string|int|DateTime|null $dt1
     * @param string|int|DateTime|null $dt2
     * @return bool
     */
    function datetime_is_between(null|string|int|DateTime $datetime, null|string|int|DateTime $dt1 = null, null|string|int|DateTime $dt2 = null)
    {
        $datetime = cast_datetime($datetime);
        if ($datetime === null) {
            return false;
        }

        $datetime1 = cast_datetime($dt1);
        if ($datetime1 !== null && $datetime <= $datetime1) {
            return false;
        }

        $datetime2 = cast_datetime($dt2);
        if ($datetime2 !== null && $datetime > $datetime2) {
            return false;
        }

        return $datetime1 != null || $datetime2 != null;
    }

    /**
     * @param string|DateTime|null $datetime
     * @param string|int|DateTime|null $d1
     * @param string|int|DateTime|null $d2
     * @return bool
     */
    function date_is_between(null|string|DateTime $datetime, null|string|int|DateTime $d1 = null, null|string|int|DateTime $d2 = null)
    {
        $datetime = cast_datetime($datetime);
        if ($datetime === null) {
            return false;
        }

        $datetime->setTime(0, 0);
        $datetime1 = cast_datetime($d1);
        $datetime1?->setTime(0, 0);
        $datetime2 = cast_datetime($d2);
        $datetime2?->setTime(0, 0);

        return datetime_is_between($datetime, $datetime1, $datetime2);
    }

    /**
     * @param string $str_starts_with
     * @param string $str_ends_with
     * @param array|null $debug_backtrace
     * @return bool
     */
    function check_backtrace(string $str_starts_with = "", string $str_ends_with = "", ?array $debug_backtrace = null)
    {
        $debug_backtrace ??= debug_backtrace();
        if ($str_starts_with === "" && $str_ends_with === "") {
            return false;
        }
        foreach ($debug_backtrace as $trace) {
            if (!array_key_exists("class", $trace)) {
                continue;
            }
            // Only apply each bound that was actually given. The old form
            // (($ends && …) && ($starts && …)) required BOTH to be non-empty,
            // so a prefix-only or suffix-only call could never match. When both
            // are given (the only current caller), the result is unchanged.
            if (
                ($str_starts_with === "" || str_starts_with($trace["class"], $str_starts_with)) &&
                ($str_ends_with === "" || str_ends_with($trace["class"], $str_ends_with))
            ) {
                return true;
            }
        }

        return false;
    }


    /**
     * @param string|DateTime|null $datetime
     * @param string|int|DateTime|null $t1
     * @param string|int|DateTime|null $t2
     * @return bool
     */
    function time_is_between(null|string|DateTime $datetime, null|string|int|DateTime $t1 = null, null|string|int|DateTime $t2 = null)
    {
        $datetime = cast_datetime($datetime);
        if ($datetime === null) {
            return false;
        }

        $datetime->setDate(0, 0, 0);
        $datetime1 = cast_datetime($t1);
        $datetime1?->setDate(0, 0, 0);
        $datetime2 = cast_datetime($t2);
        $datetime2?->setDate(0, 0, 0);

        return datetime_is_between($datetime, $datetime1, $datetime2);
    }

    /**
     * @param $query
     * @return array
     */
    function http_parse_query($query)
    {
        $parameters = [];
        $queryParts = explode('&', $query);
        foreach ($queryParts as $queryPart) {
            $keyValue = explode('=', $queryPart, 2);
            $parameters[$keyValue[0]] = $keyValue[1];
        }
        return $parameters;
    }

    /**
     * @param array $parts
     * @return string
     */
    function build_url(array $parts)
    {
        return (isset($parts['scheme']) ? "{$parts['scheme']}:" : '') .
            ((isset($parts['user']) || isset($parts['host'])) ? '//' : '') .
            (isset($parts['user']) ? "{$parts['user']}" : '') .
            (isset($parts['pass']) ? ":{$parts['pass']}" : '') .
            (isset($parts['user']) ? '@' : '') .
            (isset($parts['host']) ? "{$parts['host']}" : '') .
            (isset($parts['port']) ? ":{$parts['port']}" : '') .
            (isset($parts['path']) ? "{$parts['path']}" : '') .
            (isset($parts['query']) ? "?{$parts['query']}" : '') .
            (isset($parts['fragment']) ? "#{$parts['fragment']}" : '');
    }

    /**
     * Handling resource files
     */
    function valid_url(string $url): bool
    {
        $regex = "((https?|ftp)\:\/\/)?"; // SCHEME
        $regex .= "([a-z0-9+!*(),;?&=\$_.-]+(\:[a-z0-9+!*(),;?&=\$_.-]+)?@)?"; // User and Pass
        $regex .= "([a-z0-9-.]*)\.([a-z]{2,3})"; // Host or IP
        $regex .= "(\:[0-9]{2,5})?"; // Port
        $regex .= "(([a-z0-9+\$_-]\.?)+)*\/?"; // Path
        $regex .= "(\?[a-z+&\$_.-][a-z0-9;:@&%=+\/\$_.-]*)?"; // GET Query
        $regex .= "(#[a-z_.-][a-z0-9+\$_.-]*)?"; // Anchor

        return preg_match("/^$regex$/i", $url); // `i` flag for case-insensitive
    }

    function callable_hash(mixed $func): string
    {
        if (!is_callable($func)) {
            throw new Exception("Function must be a callable");
        }
        if (is_string($func)) {
            return $func;
        }

        if (is_array($func)) {
            if (count($func) !== 2) {
                throw new Exception("Array-callables must have exactly 2 elements");
            }
            if (!is_string($func[1])) {
                throw new Exception("Second element of array-callable must be a string function name");
            }
            if (is_object($func[0])) {
                return spl_object_hash($func[0]) . '::' . $func[1];
            } elseif (is_string($func[0])) {
                return implode('::', $func);
            }
            throw new Exception("First element of array-callable must be a class name or object instance");
        }

        if (is_object($func)) {
            return spl_object_hash($func);
        }

        throw new Exception("Unhandled callable type");
    }
}
