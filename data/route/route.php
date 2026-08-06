<?php
use think\facade\Route;

Route::get('Social Media Disclosures$', 'portal/Page/index?id=15')->append(array('id' => '15',))
->pattern(array('id' => '\d+',));

Route::get('list/:id', 'portal/List/index')
->pattern(array('id' => '\d+',));

Route::get('page/:id', 'portal/Page/index')
->pattern(array('id' => '\d+',));

Route::get('article/:id/[:cid]', 'portal/Article/index')
->pattern(array('id' => '\d+',  'cid' => '\d+',));


