<?php

namespace App\Models;

// 确保引入的是 MongoDB 的 Model
use MongoDB\Laravel\Eloquent\Model; 

class Asset extends Model
{
    // 1. 指定连接
    protected $connection = 'mongodb'; 
    
    // 2. 指定集合名
    protected $collection = 'assets'; 
    
    // 3. 白名单：允许批量写入的字段（注意拼写是 fillable，没有 s）
    protected $fillable = ['coin', 'balance', 'source']; 
}