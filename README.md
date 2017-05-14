# Silex Backend
Light structure for backend app based on [Silex Framework](https://silex.sensiolabs.org/)

Result app is a catalog of movies, with posters and user authorization.
http://www.omdbapi.com/ is used as test API

List of features:
1. Download movies data from API.
2. MongoDB is used for storage and GridFS as file storage.
3. Download and save posters of movies on-the-fly or by console command.
4. Multi-threading curl for downloads.

## Installation

1. Clone or download this repository
2. Execute `composer update` in console.
3. Change .env if needed.

_MONGO_DB_URL_ - config url for MongoDB

## Console

Console commands can be executed by `php bin/console.php`

### Mongo Backup

With `php bin/console.php mongo:backup` you can backup your mongo database in "backup" dir.
Command has a few arguments:
- gzip - Use gzip to backup
- limit - Limit backup files, old will be deleted

### Movies Update

`php bin/console.php movies:backup`

Update movies data from API.

This command update only 1000 movies with random ids. This is done for the test.

### Posters Update

`php bin/console.php movies:posters-update`

Download posters and save content to GridFS.

`php bin/console.php movies:posters-update --multi=10`

Do the same thing in 10 threads with curl multi-threading.

## API

Api use JSON as output

### GET /v1/movies

Output data and posters of movies. By default, 10 movies are outputed.
You can use limit and offset to navigate.

For example:

`GET /v1/user/movies?limit=10&offset=10`

Return second page of results.

### GET /v1/poster/POSTER

POSTER is filename of a poster.

`GET /v1/poster/59177ad04fec5a1f18005462.jpg`

Poster will be downloaded on-the-fly or requested from GridFS.

Also, you can generate new size of poster on-the-fly.

`GET /v1/poster/59177ad04fec5a1f18005462_100x0.jpg`,
`GET /v1/poster/59177ad04fec5a1f18005462.jpg?w=100`

This request generate new size of poster with Imagick.
Width will be 100 and height will be calculated by aspect ratio.

## User

Users are stored in collection "users" in MongoDB. Their sessions also stored in this collection.
You can authorize any request with a session

In any request to /user, /user/register or /user/login response with `result.session` will be returned.
You can use this session to authorize any request.

For example:

`GET /v1/user?session=fed1ce1f9fa82cb3265f997ff5488ce2`

### GET /v1/user?session=SESSION

This request is authorization check.

Return username and session if user is authorized or HTTP 401 if not.

### POST /v1/user/register

Use username and password to create a new user.

### POST /v1/user/login

Use username and password to authorize user.

### POST /v1/user/logout?session=session_id

Logout user or HTTP 401 if user is not authorized.
