<?php

declare(strict_types=1);

namespace Elastic\Apm\Tests\ComponentTests\Util;

use Elastic\Apm\Impl\Util\DbgUtil;
use JsonSerializable;
use RuntimeException;

final class IntakeApiRequest implements JsonSerializable
{
    /** @var array<string, array<string>> */
    public $headers;

    /** @var string */
    public $body;

    /** @var float */
    public $timeReceivedAtServer;

    /**
     * @return array<string, mixed>
     *
     * Called by json_encode
     * @noinspection PhpUnused
     */
    public function jsonSerialize(): array
    {
        $result = [];

        // Until this bug https://github.com/phpstan/phpstan/issues/1060 is fixed
        // @phpstan-ignore-next-line
        foreach ($this as $thisObjPropName => $thisObjPropValue) {
            $result[$thisObjPropName] = $thisObjPropValue;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $decodedJson
     */
    public static function jsonDeserialize(array $decodedJson): self
    {
        $thisObj = new self();

        // Until this bug https://github.com/phpstan/phpstan/issues/1060 is fixed
        // @phpstan-ignore-next-line
        foreach ($decodedJson as $propName => $propValue) {
            if (!property_exists($thisObj, $propName)) {
                throw new RuntimeException(
                    'Unexpected key `' . $propName . '\' - there is no corresponding property in '
                    . DbgUtil::fqToShortClassName(get_class($thisObj)) . ' class'
                );
            }
            $thisObj->$propName = $propValue;
        }

        return $thisObj;
    }
}
