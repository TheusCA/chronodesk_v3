<?php

interface IntegrationInterface {
    public function key(): string;
    public function name(): string;
    public function status(): array;
}
