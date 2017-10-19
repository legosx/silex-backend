<?php

namespace App\Helpers;

use Exception;
use Illuminate\Support\Arr;

class Curl
{
    const MULTI = 'multi';

    protected $ch = [];

    /**
     * Create instance for multi-thread curl
     *
     * @return Curl
     */
    public static function multi()
    {
        return new self;
    }

    /**
     * Add request to multi-thread curl
     *
     * @param string $index Index of request
     * @param resource $ch
     */
    public function add($index, $ch)
    {
        $this->ch[$index] = $ch;
    }

    /**
     * Run requests in multi-thread mode.
     *
     * @param null $progress Callback with two arguments, first - completed request, second - total requests
     */
    public function run($progress = null)
    {
        $mh = curl_multi_init();

        foreach ($this->ch as $ch) {
            curl_multi_add_handle($mh, $ch);
        }

        $running = null;
        $last_count = $count = count($this->ch);
        do {
            curl_multi_exec($mh, $running);
            if ($last_count > $running) {
                if (is_callable($progress)) {
                    $progress($count - $running, $count);
                }
                $last_count = $running;
            }
        } while ($running);

        foreach ($this->ch as $index => $ch) {
            $this->ch[$index] = (mb_substr(curl_getinfo($ch, CURLINFO_HTTP_CODE), 0, 1) == 2) ? curl_multi_getcontent($ch) : null;
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }

        curl_multi_close($mh);
    }

    /**
     * @param string $index Index of request
     * @return mixed
     */
    public function content($index)
    {
        return Arr::get($this->ch, $index);
    }

    /**
     * Count of added requests
     *
     * @return int
     */
    public function count()
    {
        return count($this->ch);
    }

    /**
     * Execute any request with curl options
     *
     * @param array $options Curl options
     * @return mixed|null|resource
     */
    public static function request($options = [])
    {
        $ch = curl_init();
        curl_setopt_array($ch, array_diff_key($options, [self::MULTI => true]) + [
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HEADER => false,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_TIMEOUT => 30
            ]);

        if (Arr::get($options, self::MULTI)) {
            return $ch;
        }

        try {
            $result = curl_exec($ch);
        } catch (Exception $e) {
            $result = null;
        }

        if (mb_substr(curl_getinfo($ch, CURLINFO_HTTP_CODE), 0, 1) != 2) {
            $result = null;
        }

        curl_close($ch);

        return $result;
    }


    /**
     * Execute GET request and return result
     *
     * @param string $url Request url
     * @param array $options Curl options
     * @return mixed|null|resource
     */
    public static function get($url, $options = [])
    {
        return self::request($options + [CURLOPT_URL => $url]);
    }

    /**
     * Create GET request for multi-thread curl
     *
     * @param string $url Request url
     * @param array $options Curl options
     * @return mixed|null|resource
     */
    public static function mGet($url, $options = [])
    {
        return self::get($url, $options + [self::MULTI => true]);
    }

    /**
     * Execute POST request and return result
     *
     * @param string $url Request url
     * @param array $data POST data
     * @param array $options Curl options
     * @return mixed|null|resource
     */
    public static function post($url, $data = [], $options = [])
    {
        return self::request($options + [CURLOPT_URL => $url, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $data]);
    }

    /**
     * Create POST request for multi-thread curl
     *
     * @param string $url Request url
     * @param array $data POST data
     * @param array $options Curl options
     * @return mixed|null|resource
     */
    public static function mPost($url, $data = [], $options = [])
    {
        return self::post($url, $data, $options + [self::MULTI => true]);
    }
}