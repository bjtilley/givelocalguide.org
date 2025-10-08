<?php
/**
 * GLAppConfig Helper Class
 *
 * Centralized storage for configuration or shared runtime data
 * across your WordPress theme or plugin.
 */

if ( ! class_exists( 'GLAppConfig' ) ) {
    
    class GLAppConfig {
        /**
         * Singleton instance
         *
         * @var GLAppConfig|null
         */
        private static $instance = null;
        
        /**
         * Internal data store
         *
         * @var array
         */
        private $data = [];
        
        /**
         * Private constructor to prevent direct instantiation.
         */
        private function __construct() {}
        
        /**
         * Get the single shared instance.
         *
         * @return GLAppConfig
         */
        public static function get_instance(): GLAppConfig {
            if ( self::$instance === null ) {
                self::$instance = new self();
            }
            return self::$instance;
        }
        
        /**
         * Set a value.
         *
         * @param string $key
         * @param mixed  $value
         */
        public function set( string $key, mixed $value ): void {
            $this->data[ $key ] = $value;
        }
        
        /**
         * Get a value.
         *
         * @param string $key
         * @param mixed  $default
         *
         * @return mixed
         */
        public function get( string $key, mixed $default = null ): mixed {
            return $this->data[ $key ] ?? $default;
        }
        
        /**
         * Check if a value exists.
         *
         * @param string $key
         * @return bool
         */
        public function has( string $key ): bool {
            return array_key_exists( $key, $this->data );
        }
        
        /**
         * Clear one or all stored values.
         *
         * @param string|null $key
         * @return void
         */
        public function clear( ?string $key = null ): void {
            if ( $key === null ) {
                $this->data = [];
            } else {
                unset( $this->data[ $key ] );
            }
        }
    }
}
