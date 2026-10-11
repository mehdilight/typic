<?php

declare(strict_types=1);

namespace Typic\Scanner;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use RegexIterator;
use Typic\Attribute\EnumType;
use Typic\Attribute\InputType;
use Typic\Attribute\InterfaceType;
use Typic\Attribute\Mutation;
use Typic\Attribute\ObjectType;
use Typic\Attribute\Query;
use Typic\Attribute\ResolveField;
use Typic\Attribute\Subscription;
use Typic\Attribute\UnionType;

class DirectoryScanner
{
    /**
     * @param list<string> $directories
     * @return array{resolvers: list<class-string>, types: list<class-string>}
     */
    public function scan(array $directories): array
    {
        $resolvers = [];
        $types = [];

        foreach ($directories as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            $classes = $this->findClassesInDirectory($dir);
            foreach ($classes as $class) {
                if (!class_exists($class) && !interface_exists($class) && !enum_exists($class)) {
                    continue;
                }

                $ref = new ReflectionClass($class);
                if ($ref->isAbstract()) {
                    continue;
                }

                // Check if it's a Type
                if (!empty($ref->getAttributes(ObjectType::class)) ||
                    !empty($ref->getAttributes(InputType::class)) ||
                    !empty($ref->getAttributes(EnumType::class)) ||
                    !empty($ref->getAttributes(InterfaceType::class)) ||
                    !empty($ref->getAttributes(UnionType::class))) {
                    $types[] = $class;
                }

                // Check if it's a Resolver
                $isResolver = false;
                foreach ($ref->getMethods() as $method) {
                    if (!empty($method->getAttributes(Query::class)) ||
                        !empty($method->getAttributes(Mutation::class)) ||
                        !empty($method->getAttributes(Subscription::class)) ||
                        !empty($method->getAttributes(ResolveField::class))) {
                        $isResolver = true;
                        break;
                    }
                }

                if ($isResolver) {
                    $resolvers[] = $class;
                }
            }
        }

        return [
            'resolvers' => array_values(array_unique($resolvers)),
            'types' => array_values(array_unique($types)),
        ];
    }

    /**
     * @return list<class-string>
     */
    private function findClassesInDirectory(string $dir): array
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
        $regex = new RegexIterator($iterator, '/^.+\.php$/i', RegexIterator::GET_MATCH);

        $classes = [];
        foreach ($regex as $file) {
            $filePath = $file[0];
            $class = $this->extractClassNameFromFile($filePath);
            if ($class !== null) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /**
     * @return class-string|null
     */
    private function extractClassNameFromFile(string $filePath): ?string
    {
        $content = file_get_contents($filePath);
        if ($content === false) {
            return null;
        }

        $tokens = token_get_all($content);
        $namespace = '';
        $className = '';
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i][0] === T_NAMESPACE) {
                $ns = '';
                for ($j = $i + 1; $j < $count; $j++) {
                    if ($tokens[$j] === ';') {
                        break;
                    }
                    if (is_array($tokens[$j])) {
                        $ns .= $tokens[$j][1];
                    }
                }
                $namespace = trim($ns);
            }

            if (in_array($tokens[$i][0], [T_CLASS, T_INTERFACE, T_ENUM], true)) {
                for ($j = $i + 1; $j < $count; $j++) {
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                        $className = $tokens[$j][1];
                        break 2;
                    }
                }
            }
        }

        if ($className === '') {
            return null;
        }

        /** @var class-string */
        return $namespace !== '' ? $namespace . '\\' . $className : $className;
    }
}
