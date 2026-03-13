<?php
declare(strict_types=1);

use Core\System\SystemPaths;

/**
 * Parser4 Analyzer
 *
 * PURPOSE:
 * Market structure analyzer for Brain.
 *
 * Calculates:
 *  - corridor
 *  - volatility
 *  - trend bias
 *  - strength
 *
 * DOES NOT generate trading signals.
 */

final class Parser4AnalyzerService
{
    private array $cfg = [];
    private string $moduleBase;

    private array $errors = [];
    private array $logLines = [];

    public function __construct()
    {
        $paths = SystemPaths::instance();

        $moduleKey = 'parser.parser4_analyzer';
        $this->moduleBase = (string)$paths->get($moduleKey);

        if ($this->moduleBase === '') {
            throw new RuntimeException('Parser4 module path not found');
        }

        $this->cfg = $this->loadConfig();
    }

    public function execute(): array
    {
        $t0 = microtime(true);
        $ts = date('c');

        $this->log('INFO','Parser4 Market Analyzer started');

        if (($this->cfg['enabled'] ?? true) !== true) {
            return $this->finish(false,'disabled',$t0,[]);
        }

        $symbols = $this->loadSymbolsFromParser3();

        if (!$symbols) {
            $this->log('ERROR','No symbols from parser3');
            return $this->finish(false,'no_symbols',$t0,[]);
        }

        $candidates = [];

        foreach ($symbols as $symbol) {

            $history = $this->loadHistory($symbol);

            if (count($history) < 20) {
                continue;
            }

            $corridor = $this->calculateCorridor($history);
            $volatility = $this->calculateVolatility($history);
            $trend = $this->calculateTrend($history);
            $strength = $this->calculateStrength($corridor,$volatility);

            $candidates[] = [
                'symbol'=>$symbol,
                'corridor_low'=>$corridor['low'],
                'corridor_high'=>$corridor['high'],
                'corridor_width'=>$corridor['width'],
                'volatility'=>$volatility,
                'trend_bias'=>$trend,
                'strength'=>$strength,
                'history_points'=>count($history)
            ];
        }

        usort($candidates,function($a,$b){
            return $b['strength'] <=> $a['strength'];
        });

        $this->writeJson(
            $this->moduleBase.'/storage/candidates.json',
            $candidates
        );

        return $this->finish(true,'ok',$t0,[
            'candidates'=>count($candidates)
        ]);
    }

    private function calculateCorridor(array $history): array
    {
        $low = PHP_FLOAT_MAX;
        $high = 0.0;

        foreach ($history as $p) {

            $price = $p['price'];

            if ($price < $low) {
                $low = $price;
            }

            if ($price > $high) {
                $high = $price;
            }
        }

        $width = 0.0;

        if ($low > 0) {
            $width = ($high / $low) - 1;
        }

        return [
            'low'=>round($low,8),
            'high'=>round($high,8),
            'width'=>round($width,6)
        ];
    }

    private function calculateVolatility(array $history): float
    {
        $returns = [];

        for ($i=1;$i<count($history);$i++) {

            $p1 = $history[$i-1]['price'];
            $p2 = $history[$i]['price'];

            if ($p1 > 0) {
                $returns[] = ($p2/$p1)-1;
            }
        }

        return round($this->stddev($returns),6);
    }

    private function calculateTrend(array $history): string
    {
        $first = $history[0]['price'];
        $last = $history[count($history)-1]['price'];

        if ($last > $first) {
            return 'up';
        }

        if ($last < $first) {
            return 'down';
        }

        return 'flat';
    }

    private function calculateStrength(array $corridor,float $volatility): float
    {
        if ($volatility == 0.0) {
            return 0.0;
        }

        return round($corridor['width'] / $volatility,6);
    }

    private function stddev(array $values): float
    {
        $n = count($values);

        if ($n < 2) {
            return 0.0;
        }

        $mean = array_sum($values) / $n;

        $var = 0.0;

        foreach ($values as $v) {
            $var += ($v-$mean)*($v-$mean);
        }

        return sqrt($var/($n-1));
    }

    private function loadSymbolsFromParser3(): array
    {
        $paths = SystemPaths::instance();

        $dir = $paths->get('parser.parser3.storage').'/profiles';

        if (!is_dir($dir)) {
            return [];
        }

        $files = scandir($dir);

        $symbols = [];

        foreach ($files as $f) {

            if ($f==='.' || $f==='..') {
                continue;
            }

            if (substr($f,-5)==='.json') {

                $symbol = substr($f,0,-5);

                if ($symbol!=='') {
                    $symbols[]=$symbol;
                }
            }
        }

        return $symbols;
    }

    private function loadHistory(string $symbol): array
    {
        $paths = SystemPaths::instance();

        $dir = $paths->get('parser.parser2.history').'/'.$symbol;

        if (!is_dir($dir)) {
            return [];
        }

        $files = scandir($dir);

        $ndjson = [];

        foreach ($files as $f) {

            if (substr($f,-7)==='.ndjson') {
                $ndjson[]=$f;
            }
        }

        if (!$ndjson) {
            return [];
        }

        sort($ndjson);

        $file = $dir.'/'.$ndjson[count($ndjson)-1];

        $handle = fopen($file,'r');

        if (!$handle) {
            return [];
        }

        $history = [];

        while(($line=fgets($handle))!==false){

            $data=json_decode(trim($line),true);

            if(!is_array($data)){
                continue;
            }

            if(!isset($data['price'])){
                continue;
            }

            $history[]=[
                'ts_unix'=>(int)($data['ts_unix'] ?? 0),
                'price'=>(float)$data['price']
            ];
        }

        fclose($handle);

        return $history;
    }

    private function log(string $level,string $msg): void
    {
        $this->logLines[]='['.date('Y-m-d H:i:s')."] [$level] $msg";
    }

    private function writeJson(string $path,$data): void
    {
        $dir=dirname($path);

        if(!is_dir($dir)){
            mkdir($dir,0755,true);
        }

        file_put_contents(
            $path,
            json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)
        );
    }

    private function loadConfig(): array
    {
        $path=$this->moduleBase.'/config/config.php';

        if(!is_file($path)){
            return ['enabled'=>true];
        }

        $cfg=include $path;

        if(!is_array($cfg)){
            return ['enabled'=>true];
        }

        return $cfg;
    }

    private function finish(bool $ok,string $status,float $t0,array $stats): array
    {
        $duration=(int)((microtime(true)-$t0)*1000);

        return [
            'ok'=>$ok,
            'status'=>$status,
            'duration_ms'=>$duration
        ]+$stats;
    }
}