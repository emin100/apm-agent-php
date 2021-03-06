<?php

declare(strict_types=1);

namespace Elastic\Apm\Tests\Util\Deserialization;

use Closure;
use Elastic\Apm\Impl\Util\TextUtil;
use Elastic\Apm\Tests\TestsRootDir;
use Elastic\Apm\Tests\Util\ValidationUtil;
use JsonSchema\Constraints\Constraint;
use JsonSchema\Validator;

final class ServerApiSchemaValidator
{
    private const EARLIEST_SUPPORTED_SPEC_DIR = 'earliest_supported/docs/spec';
    private const LATEST_USED_SPEC_DIR = 'latest_used/docs/spec';

    /** @var array<string, null> */
    private static $additionalPropertiesCandidateNodesKeys;

    private static function pathToSpecsRootDir(): string
    {
        return TestsRootDir::$fullPath . '/APM_Server_intake_API_schema';
    }

    private static function isAdditionalPropertiesCandidate(string $key): bool
    {
        if (!isset(self::$additionalPropertiesCandidateNodesKeys)) {
            self::$additionalPropertiesCandidateNodesKeys = ['properties' => null, 'patternProperties' => null];
        }

        return array_key_exists($key, self::$additionalPropertiesCandidateNodesKeys);
    }

    /**
     * @param string $relativePath
     *
     * @return Closure(bool): string
     */
    public static function buildPathToSchemaSupplier(string $relativePath): Closure
    {
        return function (bool $isEarliestVariant) use ($relativePath) {
            return ($isEarliestVariant ? self::EARLIEST_SUPPORTED_SPEC_DIR : self::LATEST_USED_SPEC_DIR)
                   . '/' . $relativePath;
        };
    }

    public static function validateMetadata(string $serializedData): void
    {
        self::validateEventData($serializedData, self::buildPathToSchemaSupplier('metadata.json'));
    }

    public static function validateTransactionData(string $serializedData): void
    {
        self::validateEventData($serializedData, self::buildPathToSchemaSupplier('transactions/transaction.json'));
    }

    public static function validateSpanData(string $serializedData): void
    {
        self::validateEventData($serializedData, self::buildPathToSchemaSupplier('spans/span.json'));
    }

    private static function validateEventData(string $serializedData, Closure $pathToSchemaSupplier): void
    {
        foreach ([true, false] as $isEarliestVariant) {
            $allowAdditionalPropertiesVariants = [true];
            if (!$isEarliestVariant) {
                $allowAdditionalPropertiesVariants[] = false;
            }
            foreach ($allowAdditionalPropertiesVariants as $allowAdditionalProperties) {
                self::validateEventDataAgainstSchemaVariant(
                    $serializedData,
                    $pathToSchemaSupplier($isEarliestVariant),
                    $allowAdditionalProperties
                );
            }
        }
    }

    private static function validateEventDataAgainstSchemaVariant(
        string $serializedData,
        string $relativePathToSchema,
        bool $allowAdditionalProperties
    ): void {
        $validator = new Validator();
        $deserializedRawData = SerializationTestUtil::deserializeJson($serializedData, /* asAssocArray */ false);
        $validator->validate(
            $deserializedRawData,
            (object)(self::loadSchema(
                self::normalizePath(self::pathToSpecsRootDir() . '/' . $relativePathToSchema),
                $allowAdditionalProperties
            )),
            Constraint::CHECK_MODE_VALIDATE_SCHEMA
        );
        if (!$validator->isValid()) {
            throw self::buildException($validator, $serializedData);
        }
    }

    private static function normalizePath(string $absolutePath): string
    {
        $result = realpath($absolutePath);
        if ($result === false) {
            throw ValidationUtil::buildException("realpath failed. absolutePath: `$absolutePath'");
        }
        return $result;
    }

    /**
     * @param string $absolutePath
     * @param bool   $allowAdditionalProperties
     *
     * @return array<string, mixed>
     */
    private static function loadSchema(string $absolutePath, bool $allowAdditionalProperties): array
    {
        if ($allowAdditionalProperties) {
            return ['$ref' => self::convertPathToFileUrl($absolutePath)];
        }

        $decodedSchema = self::loadSchemaAndResolveRefs($absolutePath);
        self::processSchema(/* ref */ $decodedSchema, $allowAdditionalProperties);
        $pathToTempFileWithProcessedSchema = self::writeProcessedSchemaToTempFile($decodedSchema);
        return ['$ref' => self::convertPathToFileUrl($pathToTempFileWithProcessedSchema)];
    }

    private static function convertPathToFileUrl(string $absolutePath): string
    {
        if (DIRECTORY_SEPARATOR === '/') {
            return 'file://' . $absolutePath;
        }

        return 'file:///' . str_replace('\\', '/', $absolutePath);
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return string - Absolute path to the temp file
     */
    private static function writeProcessedSchemaToTempFile(array $schema): string
    {
        $pathToTempFile = sys_get_temp_dir() . '/' . str_replace('\\', '_', __CLASS__) . '_temp_processed_schema.json';
        $numberOfBytesWritten = file_put_contents(
            $pathToTempFile,
            SerializationTestUtil::prettyEncodeJson($schema),
            /* flags */ LOCK_EX
        );
        if ($numberOfBytesWritten === false) {
            throw ValidationUtil::buildException("Failed to write to temp file `$pathToTempFile'");
        }
        return $pathToTempFile;
    }

    /**
     * @param string $absolutePath
     *
     * @return array<string, mixed>
     */
    private static function loadSchemaAndResolveRefs(string $absolutePath): array
    {
        $fileContents = file_get_contents($absolutePath);
        if ($fileContents === false) {
            throw ValidationUtil::buildException("Failed to load schema from `$absolutePath'");
        }
        $decodedSchema = SerializationTestUtil::deserializeJson($fileContents, /* asAssocArray */ true);
        self::resolveRefs($absolutePath, /* ref */ $decodedSchema);
        return $decodedSchema;
    }

    /**
     * @param string               $absolutePath
     * @param array<string, mixed> $decodedSchemaNode
     */
    private static function resolveRefs(string $absolutePath, array &$decodedSchemaNode): void
    {
        foreach ($decodedSchemaNode as $key => $value) {
            if (is_array($value)) {
                self::resolveRefs($absolutePath, /* ref */ $decodedSchemaNode[$key]);
            }
        }

        if (!array_key_exists('$ref', $decodedSchemaNode)) {
            return;
        }

        $refValue = $decodedSchemaNode['$ref'];
        self::loadRefAndMerge($absolutePath, /* ref */ $decodedSchemaNode, $refValue);
    }

    /**
     * @param string               $absolutePath
     * @param array<string, mixed> $refParentNode
     * @param string               $refValue
     */
    private static function loadRefAndMerge(string $absolutePath, array &$refParentNode, string $refValue): void
    {
        $schemaFromRef = self::loadSchemaAndResolveRefs(self::normalizePath(dirname($absolutePath) . '/' . $refValue));
        foreach ($schemaFromRef as $key => $value) {
            if (!array_key_exists($key, $refParentNode)) {
                $refParentNode[$key] = $value;
            }
        }
    }

    /**
     * @param array<string, mixed> $decodedSchema
     * @param bool                 $allowAdditionalProperties
     */
    private static function processSchema(array &$decodedSchema, bool $allowAdditionalProperties): void
    {
        self::mergeAllOfFromRef(/* ref */ $decodedSchema);
        if (!$allowAdditionalProperties) {
            self::disableAdditionalProperties(/* ref */ $decodedSchema);
        }
        self::removeRedundantKeysFromRef(/* ref */ $decodedSchema);
    }

    /**
     * @param array<string, mixed> $decodedSchemaNode
     */
    private static function mergeAllOfFromRef(array &$decodedSchemaNode): void
    {
        foreach ($decodedSchemaNode as $key => $value) {
            if (is_array($value)) {
                self::mergeAllOfFromRef(/* ref */ $decodedSchemaNode[$key]);
            }
        }

        if (
            array_key_exists('allOf', $decodedSchemaNode)
            && self::atLeastOneChildFromRef($decodedSchemaNode['allOf'])
        ) {
            self::doMergeAllOfFromRef(/* ref */ $decodedSchemaNode);
            unset($decodedSchemaNode['allOf']);
        }
    }

    /**
     * @param array<string, mixed> $decodedSchemaNode
     */
    private static function doMergeAllOfFromRef(array &$decodedSchemaNode): void
    {
        foreach ($decodedSchemaNode['allOf'] as $childNode) {
            foreach ($childNode as $key => $value) {
                if ($key === '$ref' || $key === '$id' || $key === 'title') {
                    continue;
                }

                if (!array_key_exists($key, $decodedSchemaNode)) {
                    $decodedSchemaNode[$key] = $value;
                    continue;
                }

                if (!is_array($decodedSchemaNode[$key])) {
                    continue;
                }

                $dstArray = &$decodedSchemaNode[$key];
                foreach ($value as $subKey => $subValue) {
                    if (array_key_exists($key, $dstArray)) {
                        throw ValidationUtil::buildException(
                            'Failed to merge because key already exists.'
                            . "subKey: `$subKey'" . "; key: `$key'" . "; subValue: `$subValue'"
                        );
                    }
                    $dstArray[$subKey] = $subValue;
                }
            }
        }
    }

    /**
     * @param array<mixed> $allOfArray
     *
     * @return bool
     */
    private static function atLeastOneChildFromRef(array $allOfArray): bool
    {
        foreach ($allOfArray as $key => $value) {
            if (array_key_exists('$ref', $value)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string, mixed> $decodedSchemaNode
     */
    private static function disableAdditionalProperties(array &$decodedSchemaNode): void
    {
        foreach ($decodedSchemaNode as $key => $value) {
            if (is_string($key) && self::isAdditionalPropertiesCandidate($key)) {
                $decodedSchemaNode['additionalProperties'] = false;
            }
            if ($key !== 'allOf' && $key !== 'anyOf' && is_array($value)) {
                self::disableAdditionalProperties(/* ref */ $decodedSchemaNode[$key]);
            }
        }
    }

    /**
     * @param array<string, mixed> $decodedSchemaNode
     */
    private static function removeRedundantKeysFromRef(array &$decodedSchemaNode): void
    {
        foreach ($decodedSchemaNode as $key => $value) {
            if (is_array($value)) {
                self::removeRedundantKeysFromRef(/* ref */ $decodedSchemaNode[$key]);
            }
        }

        if (!array_key_exists('$ref', $decodedSchemaNode)) {
            return;
        }

        unset($decodedSchemaNode['$ref']);
    }

    private static function buildException(
        Validator $validator,
        string $serializedData
    ): ServerApiSchemaValidationException {
        $errors = $validator->getErrors();

        $errorToString = function ($error): string {
            $concatIfNotNullOrEmpty = function (string $key) use ($error): string {
                return (TextUtil::isNullOrEmptyString($error[$key])) ? '' : ("; $key: `" . $error[$key] . "'");
            };
            $result = $error['message'];
            $result .= $concatIfNotNullOrEmpty('property');
            $result .= $concatIfNotNullOrEmpty('pointer');
            return $result;
        };

        $errorsToString = function () use ($errors, $errorToString): string {
            $result = '';
            $index = 1;
            foreach ($errors as $error) {
                if ($index !== 1) {
                    $result .= PHP_EOL;
                }
                $result .= "$index) " . $errorToString($error);
                ++$index;
            }
            return $result;
        };

        $message = 'Serialized data failed APM Server Intake API JSON schema validation.';
        $message .= PHP_EOL;
        $message .= TextUtil::indent(
            'Errors [' . count($errors) . ']:'
            . PHP_EOL
            . TextUtil::indent($errorsToString())
        );
        $message .= PHP_EOL;
        $message .= TextUtil::indent(
            'Serialized data:'
            . PHP_EOL
            . TextUtil::indent(SerializationTestUtil::prettyFormatJson($serializedData))
        );

        return new ServerApiSchemaValidationException($message);
    }
}
