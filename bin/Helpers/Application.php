<?php

namespace App\Helpers;

use MongoDB\Database;
use MongoDB\Model\BSONDocument;
use Silex\Application as MainApplication;
use M1\Env\Parser;

class Application extends MainApplication
{
    /** @var Database mongodb database */
    public $db = null;
    /** @var null|BSONDocument */
    public $user = null;
    
    const SESSION_LIFETIME = 1800;

    public function __construct(array $values = array())
    {
        parent::__construct($values);

        $this->db = Config::db();
    }
}