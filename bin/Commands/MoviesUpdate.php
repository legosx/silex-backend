<?php

namespace App\Commands;

use App\Helpers\Config;
use Illuminate\Support\Arr;
use MongoDB\BSON\UTCDatetime;
use Symfony\Component\Console\Command\Command;
use App\Helpers\Curl;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use ErrorException;

class MoviesUpdate extends Command
{
    protected function configure()
    {
        $this
            ->setName('movies:update')
            ->setDescription('Update movies in database')
            ->setHelp('This command update movies in Mongo');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $config = Config::env();

        $required = ['MONGO_DB_URL', 'API_URL'];
        if (count(array_intersect($required, array_keys($config))) != count($required)) {
            throw new ErrorException(implode($required, ', ') . ' are required in .env');
        }

        $output->writeln([
            'Movies Update',
            '============'
        ]);

        $api_url = $config['API_URL'];
        $db = Config::db();

        //Update random movies just for test
        $output->write('Update movies... ');

        $total_count = 1000;
        $imdb_ids = [];
        srand();
        for ($i = 0; $i < $total_count; $i++) {
            do {
                $imdb_id = 'tt' . sprintf('%07d', rand(1, 2000000));
            } while (in_array($imdb_id, $imdb_ids));
            $imdb_ids[] = $imdb_id;
        }

        $last_procent = 0;
        foreach ($imdb_ids as $key => $imdb_id) {
            $procent = floor(($key / $total_count) * 100 / 5) * 5;
            if ($procent > $last_procent) {
                $last_procent = $procent;
                $output->write($procent . '%... ');
            }

            $imdb = @json_decode(Curl::get($api_url . '?i=' . $imdb_id), JSON_OBJECT_AS_ARRAY);

            if (!$imdb || Arr::get($imdb, 'Error')) {
                continue;
            }

            $data = [];
            $map = [
                'title' => 'Title',
                'description' => 'Plot',
                'year' => 'Year'
            ];
            foreach ($map as $field => $key) {
                $data[$field] = Arr::get(['N/A' => null, 'True' => true, 'False' => false], $val = Arr::get($imdb, $key), $val);
            }
            $data['imdb'] = $imdb;

            $document = $db->movies->findOne([
                'imdb.imdbID' => $imdb_id
            ]);

            if ($document) {
                $db->movies->updateOne([
                    '_id' => $document->_id
                ], [
                    '$set' => array_merge($data, [
                        'updated' => new UTCDatetime(time() * 1000)
                    ])
                ]);
            } else {
                $db->movies->insertOne(array_merge($data, [
                    'created' => new UTCDatetime(time() * 1000)
                ]));
            }
        }
        $output->writeln('done');

        $output->writeln([
            '',
            'END'
        ]);
    }
}