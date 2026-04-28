<?php

namespace FriendsOfRedaxo\MediaNegotiator\Commands;

use rex;
use rex_sql;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Wärmt den Media Negotiator Cache vor, indem für jeden Medientyp und jedes Bild
 * HTTP-Anfragen mit den jeweiligen Accept-Headern gestellt werden.
 */
class WarmupCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('media:negotiator:warmup')
            ->setDescription('Wärmt den Media Negotiator Cache für alle Bilder und Medientypen vor')
            ->addOption('type', 't', InputOption::VALUE_OPTIONAL, 'Nur diesen Medientyp aufwärmen')
            ->addOption('formats', 'f', InputOption::VALUE_OPTIONAL, 'Formate als Kommaliste (avif,webp,default)', 'avif,webp,default')
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Maximale Anzahl Bilder (0 = alle)', '0')
            ->addOption('base-url', 'u', InputOption::VALUE_OPTIONAL, 'Basis-URL (Standard: rex::getServer())')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Nur anzeigen was verarbeitet werden würde, keine Anfragen stellen');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $requestedType = $input->getOption('type');
        $formatsRaw = $input->getOption('formats');
        $formats = array_map('trim', explode(',', is_string($formatsRaw) ? $formatsRaw : 'avif,webp,default'));
        $limit = (int) ($input->getOption('limit') ?? 0);
        $baseUrlOption = $input->getOption('base-url');
        $baseUrl = rtrim(is_string($baseUrlOption) ? $baseUrlOption : rex::getServer(), '/');
        $dryRun = (bool) $input->getOption('dry-run');

        /** @var array<string, string> $formatAcceptHeaders */
        $formatAcceptHeaders = [
            'avif'    => 'image/avif,image/webp,*/*;q=0.8',
            'webp'    => 'image/webp,*/*;q=0.8',
            'default' => 'text/html,*/*;q=0.5',
        ];

        if ($dryRun) {
            $output->writeln('<comment>Dry-Run Modus – es werden keine Anfragen gestellt.</comment>');
        }

        // Find all media manager types that use the negotiator effect
        $sql = rex_sql::factory();
        $typeQuery = 'SELECT DISTINCT t.name FROM ' . rex::getTable('media_manager_type') . ' t '
            . 'JOIN ' . rex::getTable('media_manager_type_effect') . ' e ON e.type_id = t.id '
            . 'WHERE e.effect = :effect';
        $typeParams = ['effect' => 'negotiator'];

        if (is_string($requestedType) && $requestedType !== '') {
            $typeQuery .= ' AND t.name = :name';
            $typeParams['name'] = $requestedType;
        }

        $types = $sql->getArray($typeQuery, $typeParams);

        if (count($types) === 0) {
            $output->writeln('<comment>Keine Medientypen mit Negotiator-Effekt gefunden.</comment>');
            return Command::SUCCESS;
        }

        // Find all convertible images (skip SVG/GIF/ICO – the effect skips them too)
        $mediaQuery = 'SELECT filename FROM ' . rex::getTable('media')
            . " WHERE filetype LIKE 'image/%'"
            . " AND filetype NOT IN ('image/svg+xml', 'image/gif', 'image/x-icon', 'image/vnd.microsoft.icon')";
        if ($limit > 0) {
            $mediaQuery .= ' LIMIT ' . $limit;
        }

        $images = $sql->getArray($mediaQuery);

        if (count($images) === 0) {
            $output->writeln('<comment>Keine konvertierbaren Bilder im Medienpool gefunden.</comment>');
            return Command::SUCCESS;
        }

        // Only process formats that have a known Accept header
        $formats = array_filter($formats, static fn (string $f): bool => isset($formatAcceptHeaders[$f]));

        $total = count($types) * count($images) * count($formats);
        $output->writeln(sprintf(
            '<info>%d Medientyp(en) × %d Bild(er) × %d Format(e) = %d Anfragen</info>',
            count($types),
            count($images),
            count($formats),
            $total,
        ));

        if ($dryRun) {
            foreach ($types as $typeRow) {
                $output->writeln('  Medientyp: <comment>' . (string) $typeRow['name'] . '</comment>');
            }
            return Command::SUCCESS;
        }

        $isLocalhost = str_contains($baseUrl, 'localhost') || str_contains($baseUrl, '127.0.0.1');

        $progressBar = new ProgressBar($output, $total);
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% – %elapsed:6s% / ~%estimated:-6s%');
        $progressBar->start();

        $errors = 0;

        foreach ($types as $typeRow) {
            $typeName = (string) $typeRow['name'];
            foreach ($images as $imageRow) {
                $filename = (string) $imageRow['filename'];
                foreach ($formats as $format) {
                    $url = $baseUrl . '/index.php?rex_media_type=' . urlencode($typeName)
                        . '&rex_media_file=' . urlencode($filename);

                    $acceptHeader = $formatAcceptHeaders[$format];
                    $httpHeaders = "Accept: $acceptHeader\r\n"
                        . "User-Agent: REDAXO MediaNegotiator CacheWarmer/1.0\r\n";

                    $opts = [
                        'http' => [
                            'method'        => 'GET',
                            'header'        => $httpHeaders,
                            'ignore_errors' => true,
                            'timeout'       => 30,
                        ],
                    ];

                    if ($isLocalhost) {
                        $opts['ssl'] = [
                            'verify_peer'      => false,
                            'verify_peer_name' => false,
                        ];
                    }

                    $result = @file_get_contents($url, false, stream_context_create($opts));

                    if ($result === false) {
                        ++$errors;
                        if ($output->isVerbose()) {
                            $output->writeln("\n<error>Fehler: $typeName / $filename ($format)</error>");
                        }
                    }

                    $progressBar->advance();
                }
            }
        }

        $progressBar->finish();
        $output->writeln('');

        if ($errors > 0) {
            $output->writeln(sprintf('<error>%d von %d Anfragen fehlgeschlagen.</error>', $errors, $total));
            return Command::FAILURE;
        }

        $output->writeln(sprintf('<info>Fertig: %d Anfragen erfolgreich gestellt.</info>', $total));
        return Command::SUCCESS;
    }
}
