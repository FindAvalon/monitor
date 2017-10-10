<?php

namespace Longway\Monitor\Services\NginxLog;

use Longway\Monitor\MonitorException;
use DirectoryIterator;
use Datetime;

class NginxLogService implements NginxLogInterface
{
	protected $filename;
	protected $logFormat;
	protected $path;

	public function __construct($filename = null, $path = null, $logFormat = null)
	{
		$this->setConfig(
			$filename ?? 'access.log',
			$path ?? '/logs/nginx',
			$logFormat ?? '$remote_addr - $remote_user  [$time_local]   "$request"  $status  $body_bytes_sent   "$http_referer"  "$request_length"  "$request_time"'
		);
	}

	public function setConfig(string $filename, string $path, string $logFormat)
	{
		if ( !strpos($logFormat, '$request_length') ) throw new MonitorException('logFormat缺少必要的参数$request_length');
		if ( !strpos($logFormat, '$request_time') ) throw new MonitorException('logFormat缺少必要的参数$request_time');
		if ( !file_exists($path) ) throw new MonitorException('path不存在');

		$this->filename = $filename;
		$this->logFormat = $logFormat;
		$this->path = $path;
		
	}

	public function render()
	{
		extract($this->parseLogFormat());

		$files = $this->findFiles();

		$result = [];

		foreach ( $files as $key => $file ) {
			$result[$key] = [
				'key' => [],
				'response' => [
					'data'   => [],
					'amount' => [],
					'time'   => []
				]
			];
			$handle = fopen($file, "r");
			while( !feof($handle) )
			{
			 	if ( $item = $this->parseData(fgets($handle), $sign, $params) ) {
			 		if ( 
			 			($date = new Datetime($item['time_local'])) && 
			 			intval($item['status']) < 400 &&
			 			doubleval($item['request_time']) > 0
		 			) {

			 			$hi = $date->format('H:i');
			 			if ( !in_array($hi, $result[$key]['key']) ) {
			 				$result[$key]['key'][] = $hi;
			 			}

			 			if ( isset($result[$key]['response']['data'][$date->format('H:i')]) ) {
			 				$result[$key]['response']['data'][$date->format('H:i')]['total_time'] += doubleval($item['request_time']);
			 				$result[$key]['response']['data'][$date->format('H:i')]['amount'] += 1;
			 			} else {
			 				$result[$key]['response']['data'][$date->format('H:i')]['total_time'] = doubleval($item['request_time']);
			 				$result[$key]['response']['data'][$date->format('H:i')]['amount'] = 1;
			 			}

			 		}
			 	}
			 	
			}
			fclose($handle);

			foreach ( $result[$key]['response']['data'] as $hi => $value ) {
				$result[$key]['response']['amount'][] = $value['amount'];
				$result[$key]['response']['time'][] = $value['total_time'] / $value['amount'];
			}

			// $result[$key]['response']['']
		}
		$result = json_encode($result);

		$html = file_get_contents(__DIR__.'/../../Views/index.html');
		$html = str_replace('{{$data}}', $result, $html);
		echo $html;
	}

	public function make()
	{
		extract($this->parseLogFormat());

		$files = $this->findFiles();

		$result = [];

		foreach ( $files as $key => $file ) {
			$result[$key] = [];
			$handle = fopen($file, "r");
			while( !feof($handle) )
			{
			 	if ( $item = $this->parseData(fgets($handle), $sign, $params) ) {
			 		$result[$key][] = $item;
			 	}
			 	
			}
			fclose($handle);
		}
		return $result;
	}

	protected function findFiles()
	{
		$filename = $this->filename;
		$pattern = '/'.$filename.'-[0-9]+/';

		$dir = new DirectoryIterator($this->path);

		$files = [];

		foreach ( $dir as $file ) {
			if ( $file->isFile() ) {
				$filename = $file->getFilename();
				if ( preg_match($pattern, $filename) ) {
					if ( $index = strrpos($filename, '-') ) {
						$key = substr($filename, $index + 1, strlen($filename) - $index);
						$files[$key] = $file->getPathname();
					}
				}
			}
		}
		krsort($files);
		return $files;

	}

	protected function parseLogFormat()
	{
		$logFormat = $this->logFormat;
		preg_match_all('/\$[a-z_]+/', $logFormat, $param);

		if ( count($param) == 0 ) throw new MonitorException('logFormat解析失败');
		if ( count($param[0]) == 0 ) throw new MonitorException('logFormat解析失败');

		$params = $param[0];

		$startIndex = 0;
		$endIndex = 0;

		$result = [];
		for ( $i = 0; $i < count($params) - 1; $i++ ) {
			if ( ( $startIndex = strpos($logFormat, $params[$i], $startIndex) ) == -1 ) {
				throw new MonitorException('logFormat解析失败');
			}
			$startIndex += strlen($params[$i]);

			if ( ( $endIndex = strpos($logFormat, $params[$i + 1], $startIndex) ) == -1 ) {
				throw new MonitorException('logFormat解析失败');
			}
			$result[] = substr($logFormat, $startIndex, $endIndex - $startIndex);
		}
		return [
			'params'	=> $params,
			'sign' 		=> $result
		];
	}

	protected function parseData($data, $sign, $params)
	{
		if ( empty($data) ) return null;

		$signLen = count($sign);
		$startIndex = 0;
		$endIndex = 0;

		$result = [];

		for ( $i = 0; $i < $signLen; $i++ ) {

			if ( !($endIndex = strpos($data, $sign[$i], $startIndex)) ) {
				return null;
			}
			$content = substr($data, $startIndex, $endIndex - $startIndex);

			$startIndex += strlen($sign[$i]) + strlen($content);

			$result[ltrim($params[$i], '$')] = $content;
		}
		$result[ltrim($params[count($params) - 1], '$')] = substr($data, $startIndex, strlen($data) - 1);
		return $result;
	}


}