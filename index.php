<?php

include "DAL.php";
include "Entity.php";

Class App {

    public static function setUp($init=false) {
        try {
            DAL::connectDb('mysql:host=127.0.0.1;dbname=categorytest', 'root', '');
        } catch (PDOException $e) {
            if ($e->getCode() != 0) { throw $e; }
        }
        $sql_setup = [
            'DROP DATABASE IF EXISTS `categorytest`',
            'CREATE DATABASE `categorytest`',
            'USE `categorytest`',
            'CREATE TABLE `categories` (
             `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
             `name` VARCHAR(120) DEFAULT NULL,
             `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
             `created_at` TIMESTAMP NULL DEFAULT NULL,
             PRIMARY KEY (`id`)
           ) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8',
        ];
        if($init) {
            foreach ($sql_setup as $sql) {
                DAL::execute($sql);
            }
        }
        
    }

    public static function tearDownAfterClass() {
        DAL::execute( 'DROP DATABASE IF EXISTS `categories`' );
    }

    public function create($params) {
        $category = new Entity($params);
        $category->save(); 
    }
    public function get($id) {
        $category = Entity::getById($id);
        return $category;
    }    
    public function modify($c,$arr) {
        $category = Entity::getById($c);
        foreach( $arr as $k => $v ) {
            $category->$k = $v;
        }
        $category->save();
    }

    public function fetch($_name) {
        $cat = Entity::findOne_by_name($_name);
        print_r($cat);
    }
}

$app = new App();
$app->setUp($init=true);
$app->create( array("name" => "Action") );
$app->modify(1,array('name' => 'Comedy'));
$app->fetch("Comedy");

?>
