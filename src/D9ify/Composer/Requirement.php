<?php

namespace D9ify\Composer;

use Composer\Semver\Comparator;

/**
 * Class Requirement
 *
 * @package D9ify\Composer
 */
class Requirement
{

    /**
     * @var string
     */
    protected string $packageName;

    /**
     * @var string
     */
    protected string $version;

    /**
     * Requirement constructor.
     *
     * @param $packageName
     * @param $version
     */
    public function __construct($packageName, $version)
    {
        $this->packageName = $packageName;
        $this->version = $version;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->packageName;
    }

    /**
     * @param $incomingVersion
     *
     * @return void
     */
    public function setVersionIfGreater($incomingVersion): void
    {
        $this->version = Comparator::greaterThan($this->version, $incomingVersion) ? $this->version : $incomingVersion;
    }

    /**
     * @param $version
     *
     * @return bool
     */
    public function isGreaterThan($version): bool
    {
        return Comparator::greaterThan($this->getVersion(), $version);
    }

    /**
     * @return string
     */
    public function getVersion(): string
    {
        return $this->version;
    }

    /**
     * @param $version
     *
     * @return bool
     */
    public function isLessThan($version): bool
    {
        return Comparator::lessThan($this->getVersion(), $version);
    }
}
