<?php

namespace App\Commands;

use App\Helpers\Config;
use App\Helpers\Poster;
use Symfony\Component\Console\Command\Command;
use App\Helpers\Curl;
use Illuminate\Support\Arr;
use MongoDB\BSON\UTCDatetime;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use ErrorException;

class PostersUpdate extends Command
{
    protected function configure()
    {
        $this
            ->setName('movies:posters-update')
            ->setDescription('Update posters')
            ->setHelp('This command allows you update posters')
            ->setDefinition(
                new InputDefinition([
                    new InputOption('multi', 'm', InputOption::VALUE_OPTIONAL, 'Count of threads for curl multi-threading')
                ])
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!($mongo_url = Arr::get(Config::env(), 'MONGO_DB_URL'))) {
            throw new ErrorException('MONGO_DB_URL is required in .env');
        }

        $output->writeln([
            'Posters Update',
            '============'
        ]);

        $db = Config::db();

        $limit = 100;
        if ($multi_count = $input->getOption('multi')) {
            $limit = (int)$multi_count;
        }

        $movies_ids = [];
        $multi = null;

        do {
            $movies = $db->movies->find(array_filter([
                '_id' => $movies_ids ? ['$nin' => $movies_ids] : null,
                'poster' => [
                    '$exists' => false
                ],
                '$and' => [
                    ['imdb.Poster' => ['$ne' => '']],
                    ['imdb.Poster' => ['$ne' => null]],
                    ['imdb.Poster' => ['$exists' => true]],
                    ['imdb.Poster' => ['$ne' => 'N/A']]
                ]
            ]), [
                'limit' => $limit,
                'noCursorTimeout' => true
            ])->toArray();

            if (!$movies) {
                break;
            }

            if ($multi_count) {
                $multi = Curl::multi();
                foreach ($movies as $movie) {
                    $multi->add((string)$movie->_id, Curl::mGet($movie->imdb->Poster));
                }
                $output->write('Multi... ');
                $multi->run(function ($completed, $total) use ($output) {
                    $output->write($completed . '/' . $total . '... ');
                });
                $output->writeln('ok');
            }

            foreach ($movies as $movie) {
                $movies_ids[] = $movie->_id;

                $id = (string)$movie->_id;
                $url = $movie->imdb->Poster;

                $ext = pathinfo($url, PATHINFO_EXTENSION);
                $filename = $id . '.' . $ext;

                $output->write('Downloading ' . $filename . '... ');

                if (!($poster = $multi ? $multi->content($id) : Curl::get($url))) {
                    $output->writeln('bad url');
                    continue;
                }

                try {
                    Poster::save($filename, $poster);
                } catch (ErrorException $e) {
                    $output->writeln($e->getMessage());
                    continue;
                }

                $db->movies->updateOne([
                    '_id' => $movie->_id
                ], [
                    '$set' => [
                        'poster' => $filename,
                        'updated' => new UTCDatetime(time() * 1000),
                    ]
                ]);

                $output->writeln('ok');
            }
        } while (true);

        $output->writeln('END');
    }
}