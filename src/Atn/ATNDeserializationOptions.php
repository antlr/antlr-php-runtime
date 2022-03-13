<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Atn;

final class ATNDeserializationOptions
{
    private bool $readOnly = false;

    private bool $verifyATN;

    private bool $generateRuleBypassTransitions;

    public static function defaultOptions(): ATNDeserializationOptions
    {
        static $defaultOptions;

        if ($defaultOptions === null) {
            $defaultOptions = new ATNDeserializationOptions();
            $defaultOptions->readOnly = true;
        }

        return $defaultOptions;
    }

    public function __construct(?ATNDeserializationOptions $options = null)
    {
        $this->verifyATN = $options === null ? true : $options->verifyATN;
        $this->generateRuleBypassTransitions = $options === null ? false : $options->generateRuleBypassTransitions;
    }

    public function isReadOnly(): bool
    {
        return $this->readOnly;
    }

    public function makeReadOnly(): void
    {
        $this->readOnly = true;
    }

    public function isVerifyATN(): bool
    {
        return $this->verifyATN;
    }

    public function setVerifyATN(bool $verifyATN): void
    {
        if ($this->readOnly) {
            throw new \InvalidArgumentException('The object is read only.');
        }

        $this->verifyATN = $verifyATN;
    }

    public function isGenerateRuleBypassTransitions(): bool
    {
        return $this->generateRuleBypassTransitions;
    }

    public function setGenerateRuleBypassTransitions(bool $generateRuleBypassTransitions): void
    {
        if ($this->readOnly) {
            throw new \InvalidArgumentException('The object is read only.');
        }

        $this->generateRuleBypassTransitions = $generateRuleBypassTransitions;
    }
}
