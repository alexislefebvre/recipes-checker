<?php

/*
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpClient\HttpClient;

#[AsCommand(name: 'generate:flex-endpoint', description: 'Generates the json files required by Flex')]
class GenerateFlexEndpointCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('repository', InputArgument::REQUIRED, 'The name of the GitHub repository')
            ->addArgument('source_branch', InputArgument::REQUIRED, 'The source branch of recipes')
            ->addArgument('flex_branch', InputArgument::REQUIRED, 'The branch of the target Flex endpoint')
            ->addArgument('output_directory', InputArgument::REQUIRED, 'The directory where generated files should be stored')
            ->addOption('contrib')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repository = $input->getArgument('repository');
        $sourceBranch = $input->getArgument('source_branch');
        $flexBranch = $input->getArgument('flex_branch');
        $outputDir = $input->getArgument('output_directory');
        $contrib = $input->getOption('contrib');

        $aliases = $recipes = [];

        // stdin usually generated by `git ls-tree HEAD */*/*`

        while (false !== $line = fgets(STDIN)) {
            [$tree, $package] = explode("\t", trim($line));
            [,, $tree] = explode(' ', $tree);

            if (!file_exists($package.'/manifest.json')) {
                continue;
            }

            $manifest = json_decode(file_get_contents($package.'/manifest.json'), true);
            $version = substr($package, 1 + strrpos($package, '/'));
            $package = substr($package, 0, -1 - \strlen($version));

            $this->generatePackageJson($package, $version, $manifest, $tree, $outputDir);

            foreach ($manifest['aliases'] ?? [] as $alias) {
                $aliases[$alias] = $package;
                $aliases[str_replace('-', '', $alias)] = $package;
            }

            if (0 === strpos($package, 'symfony/') && '-pack' !== substr($package, -5)) {
                $alias = substr($package, 8);
                $aliases[$alias] = $package;
                $aliases[str_replace('-', '', $alias)] = $package;
            }

            $recipes[$package][] = $version;
            usort($recipes[$package], 'strnatcmp');
        }

        uksort($aliases, 'strnatcmp');
        uksort($recipes, 'strnatcmp');

        file_put_contents($outputDir.'/index.json', json_encode([
            'aliases' => $aliases,
            'recipes' => $recipes,
            'versions' => $contrib ? [] : HttpClient::create()->request('GET', 'https://flex.symfony.com/versions.json')->toArray(),
            'branch' => $sourceBranch,
            'is_contrib' => $contrib,
            '_links' => [
                'repository' => sprintf('github.com/%s', $repository),
                'origin_template' => sprintf('{package}:{version}@github.com/%s:%s', $repository, $sourceBranch),
                'recipe_template' => sprintf('https://api.github.com/repos/%s/contents/{package_dotted}.{version}.json?ref=%s', $repository, $flexBranch),
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return 0;
    }

    private function generatePackageJson(string $package, string $version, array $manifest, string $tree, string $outputDir)
    {
        $files = [];
        $it = new \RecursiveDirectoryIterator($package.'/'.$version);
        $it->setFlags($it::SKIP_DOTS | $it::FOLLOW_SYMLINKS);

        foreach (new \RecursiveIteratorIterator($it) as $path => $file) {
            $file = substr($path, 1 + \strlen($package.'/'.$version));
            if (is_dir($path) || 'manifest.json' === $file) {
                continue;
            }
            if ('post-install.txt' === $file) {
                $manifest['post-install-output'] = explode("\n", rtrim(str_replace("\r", '', file_get_contents($path)), "\n"));
                continue;
            }
            if ('Makefile' === $file) {
                $manifest['makefile'] = explode("\n", rtrim(str_replace("\r", '', file_get_contents($path)), "\n"));
                continue;
            }
            $contents = file_get_contents($path);
            $files[$file] = [
                'contents' => preg_match('//u', $contents) ? explode("\n", $contents) : base64_encode($contents),
                'executable' => is_executable($path),
            ];
        }

        file_put_contents(sprintf('%s/%s.%s.json', $outputDir, str_replace('/', '.', $package), $version), json_encode([
            'manifests' => [
                $package => [
                    'manifest' => $manifest,
                    'files' => $files,
                    'ref' => $tree,
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
