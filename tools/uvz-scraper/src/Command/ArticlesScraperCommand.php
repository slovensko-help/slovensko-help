<?php

namespace App\Command;

use Coyl\Git\ConsoleException;
use Coyl\Git\Git;
use DateTimeImmutable;
use Goutte\Client;
use Laminas\Feed\Reader\Entry\EntryInterface;
use Laminas\Feed\Reader\Reader;
use Laminas\Feed\Writer\Feed;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpClient\HttpClient;

class ArticlesScraperCommand extends Command
{
    const BASE_URI = 'http://www.uvzsr.sk';
    const URL = '/index.php?option=com_content&view=category&layout=blog&id=250&Itemid=153';

    protected static $defaultName = 'app:scrape:articles';

    private string $exportDir;
    private string $feedFilepath;

    public function __construct(string $exportDir)
    {
        parent::__construct();

        $this->exportDir = $exportDir;
        $this->feedFilepath = $exportDir . 'rss.xml';
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->log('Begin scraping UVZ corona articles', $output);

        $articles = $this->readFeed($this->feedFilepath, [], true);
        $articles = $this->readFeed('http://www.uvzsr.sk/index.php?option=com_content&view=category&layout=blog&id=250&Itemid=153&limitstart=0&format=feed&type=rss&limit=1000', $articles);
        $articles = $this->scrapeArticles($articles, $output);

        $feed = new Feed();
        $feed->setTitle("COVID-19 - ÚVZ SR");
        $feed->setDescription("Články o COVID-19 z webu Úradu verejného zdravotníctva Slovenskej republiky");
        $feed->setLink('http://www.uvzsr.sk/index.php?option=com_content&view=category&layout=blog&id=250&Itemid=153');
        $feed->setLastBuildDate(time());
        $feed->setLanguage('sk-SK');

        $maxDateModified = 0;

        foreach ($articles as $urlHash => $article) {
            $maxDateModified = max($maxDateModified, $article['dateModified']);

            $entry = $feed->createEntry();
            $entry->setTitle($article['title']);
            $entry->setLink($article['url']);
            $entry->setDateModified($article['dateModified']);

            if (!empty($article['content'])) {
                $entry->setContent($article['content']);
            }

            $feed->addEntry($entry);
        }

        if (is_file($this->feedFilepath)) {
            $previousFeed = Reader::importFile($this->feedFilepath);
            $feed->setDateModified($previousFeed->getDateModified());
            $feed->setLastBuildDate($previousFeed->getDateModified());

            $hasChanged = md5($feed->export('rss')) !== md5(file_get_contents($this->feedFilepath));
        } else {
            $hasChanged = true;
        }

        if ($hasChanged) {
            $feed->setDateModified($maxDateModified);
            $feed->setLastBuildDate($maxDateModified);
            file_put_contents($this->feedFilepath, $feed->export('rss'));
        }

        $this->log('Articles count: ' . count($articles), $output);
        $this->log('Preparing to push updates...', $output);
        $repo = Git::open($this->exportDir);
        $repo->add('.');

        try {
            $repo->commit('Update RSS feed at ' . date('Y-m-d H:i:s', $maxDateModified));
            $repo->push('origin', 'master');
            $this->log('Changes were pushed.', $output);
        } catch (ConsoleException $exception) {
            if (strpos($exception->getMessage(), 'nothing to commit, working tree clean') === false) {
                throw $exception;
            } else {
                $this->log('Nothing to push.', $output);
            }
        }

        $this->log('Finished', $output);

        return 0;
    }

    private function log(string $message, OutputInterface $output): void
    {
        $output->writeln(date('\[Y-m-d H:i:s\] ') . $message);
    }

    private function readFeed(string $urlOrFilepath, array $articles = [], bool $isFile = false): array
    {
        if ($isFile) {
            if (is_file($urlOrFilepath)) {
                $feed = Reader::importFile($urlOrFilepath);
            } else {
                return $articles;
            }
        } else {
            $feed = Reader::import($urlOrFilepath);
        }

        /** @var EntryInterface $entry */
        foreach ($feed as $entry) {
            $urlHash = $this->urlHash($entry->getLink());
            $article = $articles[$urlHash] ?? [];

            $article['url'] = $entry->getLink();
            $article['title'] = $entry->getTitle();
            $article['content'] = $entry->getContent();
            $article['dateModified'] = $entry->getDateModified()->getTimestamp();

            $articles[$urlHash] = $article;
        }

        return $articles;
    }

    private function urlHash(string $url): string
    {
        return md5($url);
    }

    private function scrapeArticles(array $articles, OutputInterface $output)
    {
        $client = clone $this->client();

        $crawler = $client->request('GET', self::BASE_URI . self::URL);
        $crawler->filter('.blog_more a')->each(function (Crawler $node) use (&$articles) {
            $url = $this->absoluteUrl($node->attr('href'));
            $urlHash = $this->urlHash($url);
            $article = $articles[$urlHash] ?? [];

            $article['url'] = $url;
            $article['title'] = $node->text();
            $article['dateModified'] = $article['dateModified'] ?? time();
            $article['content'] = $article['content'] ?? '';

            $articles[$urlHash] = $article;
        });

        $articles = $this->scrapeArticleContents($articles, $output);

        return $articles;
    }

    private function client()
    {
        static $client;

        if (null === $client) {
            $client = new Client(HttpClient::create([
                'timeout' => 60
            ]));
        }

        return $client;
    }

    private function absoluteUrl(string $url): string
    {
        if (strpos($url, 'http') === false) {
            return self::BASE_URI . $url;
        }

        return $url;
    }

    private function scrapeArticleContents(array $articles, OutputInterface $output)
    {
        $oneDayAgo = time() - 3600 * 24;

        foreach ($articles as $urlHash => $article) {
            if ($article['dateModified'] > $oneDayAgo || empty($article['content'])) {
                $output->writeln('Date modified: ' . date('Y-m-d H:i:s', $article['dateModified']));

                $client = clone $this->client();
                $crawler = $client->request('GET', $article['url']);

                $article['dateModified'] = $this->parseTime(trim($crawler->filter('.contentpaneopen .createdate')->text()));
                $article['content'] = trim($crawler->filter('.contentpaneopen tr:nth-child(2) td')->html());

                $articles[$urlHash] = $article;

                $output->writeln('Date modified: ' . date('Y-m-d H:i:s', $article['dateModified']));
                $output->writeln('Updating article content: ' . $article['url']);
                $output->writeln('----------------------------------------');

                sleep(1);
            }
        }

        return $articles;
    }

    private function parseTime(string $rawTime): int
    {
        $rawTime = str_replace([
            'Január',
            'Február',
            'Marec',
            'Apríl',
            'Máj',
            'Jún',
            'Júl',
            'August',
            'September',
            'Október',
            'November',
            'December',
        ], [
            '01',
            '02',
            '03',
            '04',
            '05',
            '06',
            '07',
            '08',
            '09',
            '10',
            '11',
            '12',
        ], $rawTime);

        $rawTime = trim(mb_substr($rawTime, mb_strpos($rawTime, ',') + 1));

        return DateTimeImmutable::createFromFormat('d m Y H:i', $rawTime)->getTimestamp();
    }
}
