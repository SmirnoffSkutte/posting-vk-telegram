<?php namespace Indikator\News\Updates;

use October\Rain\Database\Updates\Migration;
use Schema;

class CreatePostsTable extends Migration
{
    public function up()
    {
        Schema::create('news_posts', function($table)
        {
            $table->increments('id');
            $table->string('title', 100);
            $table->string('slug', 100);
//            Vk
            $table->timestamp('send_vk_at')->nullable();
            $table->timestamp('vk_updated_at')->nullable();
            $table->integer('vk_post_id')->nullable();
//            Telegram
            $table->timestamp('send_tg_at')->nullable();
            $table->timestamp('tg_updated_at')->nullable();
            $table->string('tg_post_id')->nullable();

            $table->text('introductory')->nullable();
            $table->longText('content')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->string('send', 1)->default(1);
            $table->string('status', 1)->default(1);
            $table->integer('statistics')->default(0);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('news_posts');
    }
}
