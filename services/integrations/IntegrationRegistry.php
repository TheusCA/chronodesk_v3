<?php
require_once __DIR__ . '/EnvironmentIntegration.php';

class IntegrationRegistry {
    public function all(): array {
        return [
            new EnvironmentIntegration('active_directory', 'Active Directory', 'AD_SERVERS'),
            new EnvironmentIntegration('senior', 'Senior', 'SENIOR_API_URL'),
            new EnvironmentIntegration('sailpoint', 'SailPoint', 'SAILPOINT_API_URL'),
            new EnvironmentIntegration('microsoft_365', 'Microsoft 365', 'M365_TENANT_ID'),
            new EnvironmentIntegration('teams', 'Microsoft Teams', 'TEAMS_ENABLED', true),
            new EnvironmentIntegration('servicenow', 'ServiceNow', 'SERVICENOW_API_URL'),
            new EnvironmentIntegration('grafana', 'Grafana', 'GRAFANA_URL'),
            new EnvironmentIntegration('zabbix', 'Zabbix', 'ZABBIX_API_URL'),
            new EnvironmentIntegration('wazuh', 'Wazuh', 'WAZUH_API_URL'),
        ];
    }

    public function statuses(): array {
        return array_map(
            static fn(IntegrationInterface $integration) => $integration->status(),
            $this->all()
        );
    }
}
