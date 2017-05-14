<?php

namespace App\Helpers;

use M1\Env\Parser;
use MongoDB\Client;
use Illuminate\Support\Arr;

class Config
{
    public static $env;

    /**
     * Parse .env to array and return result
     *
     * @return array
     */
    public static function env()
    {
        if (!self::$env) {
            self::$env = Parser::parse(file_get_contents(__DIR__ . '/../../.env'));
        }

        return self::$env;
    }

    /**
     * Connect to MongoDB and return database instance.
     *
     * This method will return false if MONGO_DB_URL or default database not set.
     *
     * @return bool|\MongoDB\Database
     */
    public static function db()
    {
        if (!($url = Arr::get(self::env(), 'MONGO_DB_URL')) || !($db_name = ltrim(parse_url($url, PHP_URL_PATH), '/'))) {
            return false;
        }
        $client = new Client($url);

        return $client->selectDatabase($db_name);
    }
}