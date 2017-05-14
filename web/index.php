<?php
require_once(__DIR__ . '/../vendor/autoload.php');

use Symfony\Component\HttpFoundation\Request;
use Illuminate\Support\Arr;
use App\Helpers\Application;
use App\Helpers\Curl;
use MongoDB\BSON\UTCDatetime;
use MongoDB\BSON\ObjectID;
use App\Helpers\Poster;

$app = new Application;

$result = ['code' => 200];

/**
 * You can use /v1/user/register to register a user
 * After that you can request /v1/user/login to get a session
 * With session you can authorize every request
 *
 * For example:
 * GET /v1/user?session=fed1ce1f9fa82cb3265f997ff5488ce2
 */

$app->before(function (Request $request, Application $app) {
    if ($session = $request->get('session')) {
        $app->user = $app->db->users->findOne([
            'session' => $session,
            'session_started' => [
                '$gte' => new UTCDatetime((time() - $app::SESSION_LIFETIME) * 1000)
            ]
        ]);
    }
});

/**
 * Get a poster for a movie. Also, you can get any size of this poster.
 *
 * For example:
 * /v1/poster/59177ad04fec5a1f18005462_1000x0.jpg or /v1/poster/59177ad04fec5a1f18005462.jpg?w=1000
 *
 * Method finds movie with id = 59177ad04fec5a1f18005462.
 * When movie found, method try to download poster from IMDB to local MongoDB.
 * If poster downloaded with success, method try to generate image with specified size
 * When one of sizes is zero, it will be calculated and aspect ratio of image will be the same as original.
 */
$app->get('/v1/poster/{image}', function (Application $app, Request $request, $image) {

    $w = (int)$request->get('w');
    $h = (int)$request->get('h');

    if ($w || $h) {
        $image = preg_replace('/(.*)(\.[^.]+)$/', '$1_' . $w . 'x' . $h . '$2', $image);
    }

    if ($raw = Poster::get($image)) {
        return Poster::send($raw);
    }

    if (!preg_match('/^([^_]+)((_(\d+)x(\d+))?)?\.(\w+)$/', $image, $matches)) {
        $app->abort(404, 'Bad name');
    }

    $id = Arr::get($matches, 1);
    $width = Arr::get($matches, 4);
    $height = Arr::get($matches, 5);
    $extension = Arr::get($matches, 6);

    if ($width > 10000 || $height > 10000 || $width . $height == '00') {
        $app->abort(404, 'Bad dimensions');
    }

    $db = $app->db;

    $document = $db->movies->findOne([
        '_id' => new ObjectID($id),
        '$or' => [
            [
                'poster' => ['$exists' => true]
            ],
            [
                '$and' => [
                    ['imdb.Poster' => ['$exists' => true]],
                    ['imdb.Poster' => ['$ne' => 'N/A']],
                    ['imdb.Poster' => ['$ne' => null]],
                    ['imdb.Poster' => ['$ne' => '']]
                ]
            ]
        ]
    ]);

    if (!$document) {
        $app->abort(404, 'Image not found');
    }

    $poster = $document->poster;
    $imdb_poster = $document->imdb->Poster;

    $original_extension = pathinfo($poster ? $poster : $imdb_poster, PATHINFO_EXTENSION);
    if ($original_extension != $extension) {
        $app->abort(404, 'Image not found. Correct extension is ' . $original_extension);
    }

    $original = $id . '.' . $extension;

    // Try to download poster
    if (!$poster) {
        if (!($posterData = Curl::get($imdb_poster))) {
            $app->abort(404, 'Failed to download poster');
        }

        try {
            Poster::save($original, $posterData);
        } catch (ErrorException $e) {
            $app->abort(404, 'Failed to save image');
        }

        $db->movies->updateOne([
            '_id' => $document->_id
        ], [
            '$set' => [
                'poster' => $original,
                'updated' => new UTCDatetime(time() * 1000),
            ]
        ]);
    }

    // Generate poster with specified size
    if ($width || $height) {
        try {
            Poster::genSize($original, $width, $height);
        } catch (ErrorException $e) {
            $app->abort(404, $e->getMessage());
        }
    }

    return Poster::send(Poster::get($image));
});

$app->get('/v1/movies', function (Application $app, Request $request) use ($result) {

    $db = $app->db;

    $limit = intval($request->get('limit'));
    if ($limit <= 0) {
        $limit = 10;
    }

    $offset = intval($request->get('offset'));
    if ($offset <= 0) {
        $offset = 0;
    }

    $result['result'] = [];

    $movies = $db->movies->find([], [
        'skip' => $offset,
        'limit' => $limit
    ]);

    foreach ($movies as $movie) {
        $poster = $movie->poster;

        /** Image exists but not downloaded */
        if (!$poster && $imdb_poster = $movie->imdb->Poster) {
            if ($ext = pathinfo($imdb_poster, PATHINFO_EXTENSION)) {
                $poster = (string)$movie->_id . '.' . $ext;
            }
        }

        $result['result'][] = [
            'id' => (string)$movie->_id,
            'title' => $movie->title,
            'description' => $movie->description,
            'year' => $movie->year,
            'poster' => $poster ? 'http://' . Arr::get($_SERVER, 'SERVER_NAME') . '/v1/poster/' . $poster : null
        ];
    }

    return $app->json($result);
});

$app->post('/v1/user/register', function (Application $app, Request $request) use ($result) {

    $db = $app->db;

    $username = $request->get('username');
    $password = $request->get('password');

    if (!$username || !$password) {
        $app->abort(404, 'Required: username, password');
    }

    $user = $db->users->findOne([
        'username' => $username
    ]);

    if ($user) {
        $app->abort(404, 'Username already exists');
    }

    $session = md5(uniqid($username));

    $db->users->insertOne([
        'username' => $username,
        'password' => md5($password),
        'session' => $session,
        'session_started' => new UTCDatetime(time() * 1000),
        'created' => new UTCDatetime(time() * 1000)
    ]);

    $result['result'] = [
        'username' => $username,
        'session' => $session
    ];

    return $app->json($result);
});

$app->post('/v1/user/login', function (Application $app, Request $request) use ($result) {

    $db = $app->db;

    $username = $request->get('username');
    $password = $request->get('password');

    if (!$username || !$password) {
        $app->abort(404, 'Required: username, password');
    }

    $user = $db->users->findOne([
        'username' => $username,
        'password' => md5($password)
    ]);

    if (!$user) {
        $app->abort(401, 'Unauthorized');
    }

    $session = md5(uniqid($user->username));

    $db->users->updateOne([
        '_id' => $user->_id
    ], [
        '$set' => [
            'session' => $session,
            'session_started' => new UTCDatetime(time() * 1000),
            'updated' => new UTCDatetime(time() * 1000)
        ]
    ]);

    $result['result'] = [
        'username' => $username,
        'session' => $session
    ];

    return $app->json($result);
});

$app->get('/v1/user', function (Application $app, Request $request) use ($result) {

    if (!$app->user) {
        return $app->abort(401, 'Unauthorized');
    }

    $result['result'] = [
        'username' => $app->user->username,
        'session' => $app->user->session
    ];

    return $app->json($result);
});

$app->get('/v1/user/logout', function (Application $app) use ($result) {

    if (!$app->user) {
        return $app->abort(401, 'Unauthorized');
    }

    $app->db->users->updateOne([
        '_id' => $app->user->_id,
    ], [
        '$set' => [
            'updated' => new UTCDatetime(time() * 1000)
        ],
        '$unset' => [
            'session' => 1,
            'session_started' => 1
        ]
    ]);

    return $app->json($result);
});

$app->error(function ($exception, $request, $code) use ($app, $result) {

    /** @var ErrorException $exception */
    $result = array_merge($result, [
        'code' => $code,
        'error' => $exception->getMessage()
    ]);

    return $app->json($result);
});

$app->run();