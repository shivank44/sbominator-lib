<?php

declare(strict_types=1);

namespace SBOMinator\Lib\Parser;

use SBOMinator\Lib\Dependency;
use SBOMinator\Lib\Enum\FileType;

class NpmParser extends BaseParser
{
    protected FileType $originFileType = FileType::NODE_PACKAGE_LOCK_FILE;
    /**
     * Expects a package-lock.json file with either "packages" key (v2+) or "dependencies" key (v1).
     * For v2+, the "packages" value is an associative array where the root package is keyed by "".
     * For v1, the "dependencies" value is an associative array of packages.
     */
    protected function parseJson(array $json): void
    {
        if (empty($json)) {
            throw new \Exception('Invalid package-lock file');
        }

        $lockfileVersion = $json['lockfileVersion'] ?? 1;

        if ($lockfileVersion >= 2) {
            if (!isset($json['packages'])) {
                throw new \Exception('Invalid package-lock file');
            }
            $packages = $json['packages'];
        } else {
            // For lockfileVersion 1, use 'dependencies' and transform to packages format
            if (!isset($json['dependencies'])) {
                throw new \Exception('Invalid package-lock file');
            }
            $packages = ["" => $json]; // Root package
            foreach ($json['dependencies'] as $name => $dep) {
                $packages["node_modules/" . $name] = $dep;
            }
        }

        // If the noDevPackages flag is set, remove dev packages (except the root package).
        if ($this->noDevPackages === true) {
            foreach ($packages as $key => $package) {
                // Skip the root package (key == "").
                if ($key !== "" && isset($package['dev']) && $package['dev'] === true) {
                    unset($packages[$key]);
                }
            }
        }

        $this->packages = $packages;
    }

    protected function findPackageByIdentifier(string $identifier): ?array
    {
        return $this->packages[$identifier] ?? null;
    }

    protected function getVersion(array $package): string
    {
        return $package['version'] ?? '';
    }

    protected function getDependencies(array $package): array
    {
        // In package-lock files, dependencies are listed under the "dependencies" key.
        if (isset($package['dependencies']) && is_array($package['dependencies'])) {
            return array_keys($package['dependencies']);
        }
        return [];
    }

    protected function resolveDependencyIdentifier(string $parentIdentifier, string $depName): string
    {
        // For npm, if the parent is the root (""), then the dependency key is "node_modules/<depName>".
        // Otherwise, append "/node_modules/<depName>" to the parent's key.
        if ($parentIdentifier === "") {
            return "node_modules/" . $depName;
        }
        return $parentIdentifier . "/node_modules/" . $depName;
    }

    protected function getTopLevelIdentifiers(): array
    {
        $top = [];
        foreach (array_keys($this->packages) as $key) {
            if ($key !== "") {
                $top[] = $key;
            }
        }
        return $top;
    }

    /**
     * Overrides the BaseParser::buildDependencyTree to strip the "node_modules/" prefix
     * from the Dependency name when constructing the object.
     *
     * @param string $identifier The package identifier.
     * @param array $visited List of identifiers visited in the current branch.
     * @return Dependency|null The built Dependency tree or null if already built.
     */
    protected function buildDependencyTree(string $identifier, array $visited = []): ?Dependency
    {
        // Call the parent method to build the tree using full keys.
        $dep = parent::buildDependencyTree($identifier, $visited);
        if ($dep !== null) {
            // Remove any leading "node_modules/" prefix from the Dependency name.
            $cleanName = preg_replace('#^(node_modules/)+#', '', $dep->getName());
            return new Dependency($cleanName, $dep->getVersion(), $dep->getOrigin(), $dep->getDependencies());
        }
        return null;
    }
}
