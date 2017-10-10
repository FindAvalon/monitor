<?php

namespace Longway\Monitor\Services\NginxLog;

interface NginxLogInterface
{
	public function setConfig(string $filename, string $path, string $logFormat);

	public function make();

	public function render();
}