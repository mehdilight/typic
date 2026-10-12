<?php

declare(strict_types=1);

namespace Typic\Tests\UploadFixtures;

use Psr\Http\Message\UploadedFileInterface;
use Typic\Attribute\Arg;
use Typic\Attribute\Mutation;
use Typic\Attribute\Query;
use Typic\Validation\MaxFileSize;
use Typic\Validation\MimeType;

class UploadResolver
{
    #[Query]
    public function ping(): string
    {
        return 'pong';
    }

    #[Mutation]
    public function upload(UploadedFileInterface $file): string
    {
        return sprintf('%s %d %s', $file->getClientFilename(), $file->getSize(), (string) $file->getStream());
    }

    /**
     * @param list<UploadedFileInterface> $files
     * @return list<string>
     */
    #[Mutation(arrayOf: 'string')]
    public function uploadMany(#[Arg(arrayOf: UploadedFileInterface::class)] array $files): array
    {
        return array_map(static fn (UploadedFileInterface $f): string => (string) $f->getClientFilename(), $files);
    }

    #[Mutation]
    public function updateProfile(ProfileInput $input): string
    {
        return $input->name . ':' . ($input->avatar?->getClientFilename() ?? 'no avatar');
    }

    #[Mutation]
    public function uploadImage(#[MaxFileSize(1024)] #[MimeType('image/*')] UploadedFileInterface $image): string
    {
        return (string) $image->getClientFilename();
    }
}
