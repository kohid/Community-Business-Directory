<?php
namespace CBD\Core;
defined( 'ABSPATH' ) || exit;
class Loader {
    private array $actions = [];
    private array $filters = [];
    public function add_action( string $hook, object $obj, string $method, int $pri = 10, int $args = 1 ): void {
        $this->actions[] = compact( 'hook', 'obj', 'method', 'pri', 'args' );
    }
    public function add_filter( string $hook, object $obj, string $method, int $pri = 10, int $args = 1 ): void {
        $this->filters[] = compact( 'hook', 'obj', 'method', 'pri', 'args' );
    }
    public function run(): void {
        foreach ( $this->actions as $a ) add_action( $a['hook'], [ $a['obj'], $a['method'] ], $a['pri'], $a['args'] );
        foreach ( $this->filters as $f ) add_filter( $f['hook'], [ $f['obj'], $f['method'] ], $f['pri'], $f['args'] );
    }
}
