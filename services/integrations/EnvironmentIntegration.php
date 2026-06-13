<?php
require_once __DIR__ . '/IntegrationInterface.php';

class EnvironmentIntegration implements IntegrationInterface {
    private string $integrationKey;
    private string $integrationName;
    private string $configurationEnv;
    private bool $booleanFlag;

    public function __construct(string $key, string $name, string $configurationEnv, bool $booleanFlag = false) {
        $this->integrationKey = $key;
        $this->integrationName = $name;
        $this->configurationEnv = $configurationEnv;
        $this->booleanFlag = $booleanFlag;
    }

    public function key(): string {
        return $this->integrationKey;
    }

    public function name(): string {
        return $this->integrationName;
    }

    public function status(): array {
        $value = (string)(getenv($this->configurationEnv) ?: '');
        $configured = $this->booleanFlag
            ? filter_var($value, FILTER_VALIDATE_BOOLEAN)
            : trim($value) !== '';
        return [
            'key' => $this->key(),
            'name' => $this->name(),
            'configured' => $configured,
            'status' => $configured ? 'configured' : 'disabled',
        ];
    }
}
