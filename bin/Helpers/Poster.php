<?php

namespace App\Helpers;

use M1\Env\Parser;
use MongoDb;
use Imagick;
use ImagickException;
use ErrorException;
use MongoDB\BSON\Regex;
use MongoDB\Driver\Exception\BulkWriteException;
use finfo;

class Poster
{
    public $name = 'poster';

    /** @return self */
    public static function obj()
    {
        $className = get_called_class();
        return new $className;
    }

    public static function get($filename)
    {
        $document = self::files()->findOne([
            'filename' => $filename
        ]);

        if (!$document) {
            return false;
        }

        $stream = self::bucket()->openDownloadStream($document->_id);
        $content = stream_get_contents($stream);

        if (!$content) {
            return false;
        }

        return $content;
    }

    public function deleteRegex($id)
    {
        return new Regex('^' . $id . '[\.|_](.*?)[png|jpg|gif]$', 'g');
    }

    public static function delete($id = null)
    {
        $remove = [];
        if ($id) {
            $remove = [
                'filename' => self::obj()->deleteRegex($id)
            ];
        }

        $files = self::files()->find($remove);
        $files_id = [];
        foreach ($files as $file) {
            $files_id[] = $file->_id;
        }

        self::files()->deleteMany([
            '_id' => [
                '$in' => $files_id
            ]
        ]);

        self::chunks()->deleteMany([
            'files_id' => [
                '$in' => $files_id
            ]
        ]);
    }

    public static function save($filename, $content, $metadata = [])
    {
        try {
            $doc = self::files()->findOne([
                'filename' => $filename
            ]);
            if ($doc) {
                self::bucket()->delete($doc->_id);
            }

            $options = [];
            if ($metadata) {
                $options['metadata'] = $metadata;
            }
            $stream = self::bucket()->openUploadStream($filename, $options);
            fwrite($stream, $content);
            fclose($stream);
        } catch (BulkWriteException $e) {
            throw new ErrorException($e->getMessage());
        }
    }

    public static function send($content, $content_type = '')
    {
        if (!$content_type) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $content_type = $finfo->buffer($content);
        }
        header('Content-Type: ' . $content_type);
        header('Content-Length: ' . strlen($content));
        echo $content;
        exit;
        return true;
    }

    public static function genSize($path, $width, $height)
    {
        if (!class_exists('Imagick')) {
            throw new ErrorException('Imagick extension is needed');
        }

        $savePath = preg_replace('/^(.*)(\.[^.]+)$/', '$1_' . $width . 'x' . $height . '$2', $path);

        try {
            $image = new Imagick();
            $image->readImageBlob(self::get($path));
            $image->thumbnailImage($width, $height);
            self::save($savePath, $image->getImageBlob());
        } catch (ImagickException $e) {
            throw new ErrorException($e->getMessage());
        }

        if (!self::get($savePath)) {
            throw new ErrorException('Failed to save image');
        }

        return true;
    }

    public static function chunks()
    {
        return Config::db()->selectCollection(self::obj()->name . '.chunks');
    }

    public static function files()
    {
        return Config::db()->selectCollection(self::obj()->name . '.files');
    }

    public static function bucket()
    {
        return Config::db()->selectGridFSBucket([
            'bucketName' => self::obj()->name
        ]);
    }
}