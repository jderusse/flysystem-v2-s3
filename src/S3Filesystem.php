<?php

declare(strict_types=1);

namespace AsyncAws\Flysystem;

use AsyncAws\Core\Exception\Http\ClientException;
use AsyncAws\Core\StreamableBody;
use AsyncAws\S3\Result\ListObjectsOutput;
use AsyncAws\S3\S3Client;
use Generator;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\FilesystemOperationFailed;
use League\Flysystem\MimeType;
use League\Flysystem\PathPrefixer;
use League\Flysystem\StorageAttributes;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\Visibility;
use Psr\Http\Message\StreamInterface;
use Throwable;

class S3Filesystem implements FilesystemAdapter
{
    /**
     * @var array
     */
    public const AVAILABLE_OPTIONS = [
        'ACL',
        'CacheControl',
        'ContentDisposition',
        'ContentEncoding',
        'ContentLength',
        'ContentType',
        'Expires',
        'GrantFullControl',
        'GrantRead',
        'GrantReadACP',
        'GrantWriteACP',
        'Metadata',
        'RequestPayer',
        'SSECustomerAlgorithm',
        'SSECustomerKey',
        'SSECustomerKeyMD5',
        'SSEKMSKeyId',
        'ServerSideEncryption',
        'StorageClass',
        'Tagging',
        'WebsiteRedirectLocation',
    ];
    private const EXTRA_METADATA_FIELDS = [
        'Metadata',
        'StorageClass',
        'ETag',
        'VersionId',
    ];

    /**
     * @var S3Client
     */
    private $client;

    /**
     * @var PathPrefixer
     */
    private $prefixer;

    /**
     * @var string
     */
    private $bucket;

    /**
     * @var VisibilityConverter
     */
    private $visibility;

    public function __construct(
        S3Client $client,
        string $bucket,
        string $prefix = '',
        VisibilityConverter $visibility = null
    ) {
        $this->client = $client;
        $this->prefixer = new PathPrefixer($prefix);
        $this->bucket = $bucket;
        $this->visibility = $visibility ?: new PortableVisibilityConverter();
    }

    public function fileExists(string $path): bool
    {
        try {
            $this->client->getObject(
                [
                    'Bucket' => $this->bucket,
                    'Key' => $this->prefixer->prefixPath($path),
                ]
            )->resolve();
            return true;
        } catch (ClientException $e) {
            return false;
        }
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $this->upload($path, $contents, $config);
    }

    /**
     * @param string          $path
     * @param string|resource $body
     * @param Config          $config
     */
    private function upload(string $path, $body, Config $config): void
    {
        $key = $this->prefixer->prefixPath($path);
        $acl = $this->determineAcl($config);
        $options = $this->createOptionsFromConfig($config);
        $shouldDetermineMimetype = $body !== '' && ! array_key_exists('ContentType', $options);

        if ($shouldDetermineMimetype && $mimeType = MimeType::detectMimeType($key, $body)) {
            $options['ContentType'] = $mimeType;
        }

        $this->client->putObject(array_merge($options, [
            'Bucket'=> $this->bucket,
            'Key' => $key,
            'Body' => $body,
            'ACL' => $acl,
            ]))->resolve();
    }

    private function determineAcl(Config $config): string
    {
        $visibility = (string) $config->get(Config::OPTION_VISIBILITY, Visibility::PRIVATE);

        return $this->visibility->visibilityToAcl($visibility);
    }

    private function createOptionsFromConfig(Config $config): array
    {
        $options = [];

        foreach (static::AVAILABLE_OPTIONS as $option) {
            $value = $config->get($option, '__NOT_SET__');

            if ($value !== '__NOT_SET__') {
                $options[$option] = $value;
            }
        }

        return $options;
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        $this->upload($path, $contents, $config);
    }

    public function read(string $path): string
    {
        $body = $this->readObject($path);

        return $body->getContentAsString();
    }

    public function readStream(string $path)
    {
        $body = $this->readObject($path);

        return $body->getContentAsResource();
    }

    public function delete(string $path): void
    {
        $arguments = ['Bucket' => $this->bucket, 'Key' => $this->prefixer->prefixPath($path)];
        $result = $this->client->deleteObject($arguments);

        try {
            $result->resolve();
        } catch (Throwable $exception) {
            throw UnableToDeleteFile::atLocation($path, '', $exception);
        }
    }

    public function deleteDirectory(string $path): void
    {
        $prefix = $this->prefixer->prefixPath($path);
        $prefix = ltrim(rtrim($prefix, '/') . '/', '/');

        // TODO fixme
        $this->client->deleteMatchingObjects($this->bucket, $prefix);
    }

    public function createDirectory(string $path, Config $config): void
    {
        $this->upload(rtrim($path, '/') . '/', '', $config->withDefaults([
            'visibility' => $this->visibility->defaultForDirectories(),
       ]));
    }

    public function setVisibility(string $path, $visibility): void
    {
        $arguments = [
            'Bucket' => $this->bucket,
            'Key'    => $this->prefixer->prefixPath($path),
            'ACL'    => $this->visibility->visibilityToAcl($visibility),
        ];
        $result = $this->client->putObjectAcl($arguments);

        try {
            $result->resolve();
        } catch (Throwable $exception) {
            throw UnableToSetVisibility::atLocation($path, '', $exception);
        }
    }

    public function visibility(string $path): FileAttributes
    {
        $arguments = ['Bucket' => $this->bucket, 'Key' => $this->prefixer->prefixPath($path)];
        $result = $this->client->getObjectAcl($arguments);

        try {
            $grants = $result->getGrants();
        } catch (Throwable $exception) {
            throw UnableToRetrieveMetadata::visibility($path, '', $exception);
        }

        $visibility = $this->visibility->aclToVisibility($grants);

        return new FileAttributes($path, null, $visibility);
    }

    private function fetchFileMetadata(string $path, string $type): FileAttributes
    {
        $arguments = ['Bucket' => $this->bucket, 'Key' => $this->prefixer->prefixPath($path)];
        $result = $this->client->headObject($arguments);

        try {
            $result->resolve();
        } catch (Throwable $exception) {
            throw UnableToRetrieveMetadata::create($path, $type, '', $exception);
        }

        // TODO verify Metadata on result.  https://github.com/async-aws/aws/issues/91
        $attributes = $this->mapS3ObjectMetadata($result, $path);

        if ( ! $attributes instanceof FileAttributes) {
            throw UnableToRetrieveMetadata::create($path, $type, '');
        }

        return $attributes;
    }

    private function mapS3ObjectMetadata(array $metadata, $path = null): StorageAttributes
    {
        if ($path === null) {
            $path = $this->prefixer->stripPrefix($metadata['Key'] ?? $metadata['Prefix']);
        }

        if (substr($path, -1) === '/') {
            return new DirectoryAttributes(rtrim($path, '/'));
        }

        $mimetype = $metadata['ContentType'] ?? null;
        $fileSize = $metadata['ContentLength'] ?? $metadata['Size'] ?? null;
        $lastModified = null;
        $dateTime = $metadata['LastModified'] ?? null;

        if ($dateTime instanceof \DateTimeInterface) {
            $lastModified = $dateTime->getTimestamp();
        }

        return new FileAttributes(
            $path, (int) $fileSize, null, $lastModified, $mimetype, $this->extractExtraMetadata($metadata)
        );
    }

    private function extractExtraMetadata(array $metadata): array
    {
        $extracted = [];

        foreach (static::EXTRA_METADATA_FIELDS as $field) {
            if (isset($metadata[$field]) && $metadata[$field] !== '') {
                $extracted[$field] = $metadata[$field];
            }
        }

        return $extracted;
    }

    public function mimeType(string $path): FileAttributes
    {
        return $this->fetchFileMetadata($path, FileAttributes::ATTRIBUTE_MIME_TYPE);
    }

    public function lastModified(string $path): FileAttributes
    {
        return $this->fetchFileMetadata($path, FileAttributes::ATTRIBUTE_LAST_MODIFIED);
    }

    public function fileSize(string $path): FileAttributes
    {
        return $this->fetchFileMetadata($path, FileAttributes::ATTRIBUTE_FILE_SIZE);
    }

    public function listContents(string $path, bool $recursive): Generator
    {
        $prefix = $this->prefixer->prefixPath($path);
        $options = ['Bucket' => $this->bucket, 'Prefix' => trim($prefix, '/') . '/'];

        if ($recursive === false) {
            $options['Delimiter'] = '/';
        }

        $listing = $this->retrievePaginatedListing($options);

        foreach ($listing as $item) {
            // TODO verify if this is the correct data to send.
            yield $this->mapS3ObjectMetadata($item);
        }
    }

    private function retrievePaginatedListing(array $options): Generator
    {
        $result = $this->client->listObjects($options);

        /** @var ListObjectsOutput $item */
        foreach ($result->iterable() as $item) {
            yield from ($item->getCommonPrefixes());
            yield from ($item->getContents());
        }
    }

    public function move(string $source, string $destination, Config $config): void
    {
        try {
            $this->copy($source, $destination, $config);
            $this->delete($source);
        } catch (FilesystemOperationFailed $exception) {
            throw UnableToMoveFile::fromLocationTo($source, $destination, $exception);
        }
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        try {
            $visibility = $this->visibility($source)->visibility();
        } catch (Throwable $exception) {
            throw UnableToCopyFile::fromLocationTo(
                $source,
                $destination,
                $exception
            );
        }
        $arguments = [
            'ACL'        => $this->visibility->visibilityToAcl($visibility),
            'Bucket'     => $this->bucket,
            'Key'        => $this->prefixer->prefixPath($destination),
            'CopySource' => rawurlencode($this->bucket . '/' . $this->prefixer->prefixPath($source)),
        ];
        $result = $this->client->copyObject($arguments);

        try {
            $result->resolve();
        } catch (Throwable $exception) {
            throw UnableToCopyFile::fromLocationTo($source, $destination, $exception);
        }
    }

    private function readObject(string $path): StreamableBody
    {
        $options = ['Bucket' => $this->bucket, 'Key' => $this->prefixer->prefixPath($path)];
        $result = $this->client->getObject($options);

        try {
            return $result->getBody();
        } catch (Throwable $exception) {
            throw UnableToReadFile::fromLocation($path, '', $exception);
        }
    }
}
