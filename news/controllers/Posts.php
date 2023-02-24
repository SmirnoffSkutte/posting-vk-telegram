<?php namespace Indikator\News\Controllers;

use Backend\Classes\Controller;
use BackendMenu;
use BackendAuth;
use App;
use File;
use Illuminate\Support\Facades\Log;
use Mail;
use Request;
use Indikator\News\Models\Posts as Item;
use Indikator\News\Classes\NewsSender;
use Carbon\Carbon;
use Flash;
use Lang;
use Redirect;
use Indikator\News\Models\Settings;
use CURLFile;

class Posts extends Controller
{
    public $implement = [
        \Backend\Behaviors\FormController::class,
        \Backend\Behaviors\ListController::class,
        \Backend\Behaviors\ImportExportController::class,
        \Backend\Behaviors\RelationController::class
    ];

    public $formConfig = 'config_form.yaml';
    public $listConfig = 'config_list.yaml';
    public $importExportConfig = 'config_import_export.yaml';
    public $relationConfig = 'config_relation.yaml';

    public $requiredPermissions = ['indikator.news.posts'];

    public $bodyClass = 'compact-container';

    public function __construct()
    {
        parent::__construct();

        BackendMenu::setContext('Indikator.News', 'news', 'posts');

        Item::extend(function ($model) {
            $model->bindEvent('model.beforeCreate', function () use ($model) {
                $data=post();
                $checkboxVk=$data['Posts']['send_vk_checkbox'];
                $checkboxTg=$data['Posts']['send_telegram_checkbox'];
                if($checkboxVk){
                    $model->send_vk_at=now();
                    $resPostId=$this->sendPostInVk($data['Posts']);
                    $model->vk_post_id=$resPostId;
                } else {
                    $model->send_vk_at =null;
                }
                if($checkboxTg){
                    $model->send_tg_at=now();
                    $resIds=$this->sendPostInTelegram($data['Posts']);
                    $model->tg_post_id=$resIds;
                } else {
                    $model->send_tg_at =null;
                }
            });

            $model->bindEvent('model.beforeUpdate', function () use ($model) {
                $data=post();
                $checkboxVk=$data['Posts']['send_vk_checkbox'];
                $checkboxTg=$data['Posts']['send_telegram_checkbox'];
                if($checkboxVk){
                    $model->vk_updated_at=now();
                    $postId=$model->vk_post_id;
                    $this->updateVkPost($data['Posts'],$postId);
                } else {
                    $model->vk_updated_at =null;
                }
//                if($checkboxTg){
//                    $model->tg_updated_at=now();
//                    $postIds=$model->tg_post_id;
//                    $resIds=$this->updatePostInTelegram($data['Posts'],$postIds);
//                } else {
//                    $model->tg_updated_at=null;
//                }
            });
        });
    }

    private function sendPostInVk($post)
    {
        $rootDir = realpath($_SERVER["DOCUMENT_ROOT"]);
        $group_id = Settings::get('vk_group_id');
        $access_token = Settings::get('vk_token');
        $vk_api_version = Settings::get('vk_api_version');

        $imageSRCs = [];
        $postMainImage=null;
        if($post['image']){
            $postMainImage = $rootDir . '/storage/app/media' . $post['image'];
            array_unshift($imageSRCs, $postMainImage);
        }
        $postIntroImages = $this->getPostIntroImages($post);
        $postImages = $this->getPostImages($post);
        $imageSRCs = array_merge($imageSRCs, $postIntroImages, $postImages);

        $title = strip_tags($post['title']);
        $intro = strip_tags($post['introductory']);
        $message = strip_tags($post['content']);
        $postText = $title . PHP_EOL . $intro . PHP_EOL . $message;
        if (empty($imageSRCs))
        {
            $params = array(
                'v' => $vk_api_version,
                'access_token' => $access_token,
                'owner_id' => '-' . $group_id,
                'from_group' => '1',
                'message' => $postText,
            );
            $res = file_get_contents('https://api.vk.com/method/wall.post?' . http_build_query($params));
            $resArray = json_decode($res, true);
            return $resArray['response']['post_id'];
        }

        // Получение сервера vk для загрузки изображения.
        $server = file_get_contents('https://api.vk.com/method/photos.getWallUploadServer?group_id=' . $group_id . '&access_token=' . $access_token . '&v=' . $vk_api_version);
        $server = json_decode($server);

        $savedImages = [];
        if (!empty($server->response->upload_url)) {
            // Отправка изображений на сервер.
            foreach ($imageSRCs as $image) {
                $postdata['file1'] = curl_file_create($image);

                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $server->response->upload_url);
                curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: multipart/form-data'));
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $postdata);

                $upload = curl_exec($ch);
                curl_close($ch);
                $upload = json_decode($upload);
                if (!empty($upload->server)) {
                    // Сохранение фото в группе.
                    $save = file_get_contents('https://api.vk.com/method/photos.saveWallPhoto?group_id=' . $group_id . '&server=' . $upload->server . '&photo=' . stripslashes($upload->photo) . '&hash=' . $upload->hash . '&access_token=' . $access_token . '&v=' . $vk_api_version);
                    array_push($savedImages, $save);
                }
            }
        }
        if (!empty($savedImages)) {
            $savedImagesAttachments = "";
            foreach ($savedImages as $imageId) {
                $imageInfo = json_decode($imageId, true);
                $savedImagesAttachments = $savedImagesAttachments . 'photo' . $imageInfo['response'][0]['owner_id'] . '_' . $imageInfo['response'][0]['id'] . ',';
            }
            // Отправляем пост.
            $params = array(
                'v' => $vk_api_version,
                'access_token' => $access_token,
                'owner_id' => '-' . $group_id,
                'from_group' => '1',
                'message' => $postText,
                'attachments' => $savedImagesAttachments
            );

            $res = file_get_contents('https://api.vk.com/method/wall.post?' . http_build_query($params));
            $resArray = json_decode($res, true);
            return $resArray['response']['post_id'];
        }
//        trace_log($imageSRCs);

    }

    private function updateVkPost($post,$postId)
    {
        $rootDir = realpath($_SERVER["DOCUMENT_ROOT"]);
        $group_id=Settings::get('vk_group_id');
        $access_token=Settings::get('vk_token');
        $vk_api_version=Settings::get('vk_api_version');

        $imageSRCs = [];
        $postMainImage=null;
        if($post['image']){
            $postMainImage = $rootDir . '/storage/app/media' . $post['image'];
            array_unshift($imageSRCs, $postMainImage);
        }
        $postIntroImages = $this->getPostIntroImages($post);
        $postImages = $this->getPostImages($post);
        $imageSRCs = array_merge($imageSRCs, $postIntroImages, $postImages);

        $title=strip_tags($post['title']);
        $intro=strip_tags($post['introductory']);
        $message=strip_tags($post['content']);
        $postText=$title.PHP_EOL.$intro.PHP_EOL.$message;
        if (empty($imageSRCs))
        {
            $params = array(
                'v' => $vk_api_version,
                'access_token' => $access_token,
                'owner_id' => '-' . $group_id,
                'from_group' => '1',
                'message' => $postText,
            );
            $res = file_get_contents('https://api.vk.com/method/wall.post?' . http_build_query($params));
            $resArray = json_decode($res, true);
        }
        // Получение сервера vk для загрузки изображения.
        $server = file_get_contents('https://api.vk.com/method/photos.getWallUploadServer?group_id=' . $group_id . '&access_token=' . $access_token . '&v=' . $vk_api_version);
        $server = json_decode($server);

        $savedImages=[];
        if (!empty($server->response->upload_url)) {
            // Отправка изображений на сервер.
            foreach ($imageSRCs as $image) {
                $postdata['file1'] = curl_file_create($image);

                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $server->response->upload_url);
                curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: multipart/form-data'));
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $postdata);

                $upload = curl_exec($ch);
                curl_close($ch);
                $upload = json_decode($upload);
                if (!empty($upload->server)) {
                    // Сохранение фото в группе.
                    $save = file_get_contents('https://api.vk.com/method/photos.saveWallPhoto?group_id=' . $group_id . '&server=' . $upload->server . '&photo=' . stripslashes($upload->photo) . '&hash=' . $upload->hash . '&access_token=' . $access_token . '&v=' . $vk_api_version);
                    array_push($savedImages,$save);
                }
            }
        }
        if (!empty($savedImages)) {
            $savedImagesAttachments="";
            foreach ($savedImages as $imageId){
                $imageInfo=json_decode($imageId,true);
                $savedImagesAttachments=$savedImagesAttachments.'photo'.$imageInfo['response'][0]['owner_id'].'_'.$imageInfo['response'][0]['id'].',';
            }
            // Отправляем пост.
            $params = array(
                'v'            => $vk_api_version,
                'post_id' =>$postId,
                'access_token' => $access_token,
                'owner_id'     => '-'.$group_id,
                'from_group'   => '1',
                'message'      => $postText,
                'attachments'  => $savedImagesAttachments
            );

            file_get_contents('https://api.vk.com/method/wall.edit?' . http_build_query($params));
        }
    }

    private function sendPostInTelegram($post)
    {
        $rootDir = realpath($_SERVER["DOCUMENT_ROOT"]);
        $botApiToken = Settings::get('tg_bot_token');
        $chatId=Settings::get('tg_channel_link');

        $title = strip_tags($post['title']);
        $intro = strip_tags($post['introductory']);
        $message = strip_tags($post['content']);
        $postText = $title . PHP_EOL . $intro . PHP_EOL . $message;

        $imageSRCs = [];
        $tgImages=[];
        $postMainImage=null;
        if($post['image']){
            $postMainImage = $rootDir . '/storage/app/media' . $post['image'];
            array_unshift($imageSRCs, $postMainImage);
        }
        $postIntroImages = $this->getPostIntroImages($post);
        $postImages = $this->getPostImages($post);
        $imageSRCs = array_merge($imageSRCs, $postIntroImages, $postImages);

        if (empty($imageSRCs))
        {
            $data = [
                'chat_id' => $chatId,
                'text' => $postText
            ];

            $resp = file_get_contents("https://api.telegram.org/bot{$botApiToken}/sendMessage?" . http_build_query($data) );
            $resArr=json_decode($resp,true);
            return $resArr['result']['message_id'];
        }
        trace_log($imageSRCs);
        $i=0;
        foreach ($imageSRCs as $image){
            if ($i==0){
                array_push($tgImages,['type'=>'photo','media'=>"attach://file$i.png",'caption'=>$postText]);
            } else {
                array_push($tgImages,['type'=>'photo','media'=>"attach://file$i.png"]);
            }
            $i++;
        }
        trace_log(json_encode($tgImages));

        $dataPhoto = [
            'chat_id' => $chatId,
            'media' => json_encode($tgImages),
        ];

        $j=0;
        foreach ($imageSRCs as $image){
            $dataPhoto["file$j.png"] = new CURLFile(realpath($image));
            $j++;
        }

        trace_log($dataPhoto);

        $ch = curl_init('https://api.telegram.org/bot'. $botApiToken .'/sendMediaGroup');
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $dataPhoto);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        $res = curl_exec($ch);
        trace_log($res);
        curl_close($ch);

        $messageIds='';
        $resArr=json_decode($res,true);
        $resMessages=$resArr['result'];
        foreach ($resMessages as $message){
            $messageIds=$messageIds.$message['message_id'].',';
        }

        trace_log($messageIds);
        return $messageIds;
    }

    private function updatePostInTelegram($post,$messageIds)
    {
        $rootDir = realpath($_SERVER["DOCUMENT_ROOT"]);
        $botApiToken = Settings::get('tg_bot_token');
        $chatId=Settings::get('tg_channel_link');
        $messageIds=rtrim($messageIds,',');
        $messageIds=explode(',',$messageIds);

        $title = strip_tags($post['title']);
        $intro = strip_tags($post['introductory']);
        $message = strip_tags($post['content']);
        $postText = $title . PHP_EOL . $intro . PHP_EOL . $message;

        $imageSRCs = [];
        $tgImages=[];
        $postMainImage=null;
        if($post['image']){
            $postMainImage = $rootDir . '/storage/app/media' . $post['image'];
            array_unshift($imageSRCs, $postMainImage);
        }
        $postIntroImages = $this->getPostIntroImages($post);
        $postImages = $this->getPostImages($post);
        $imageSRCs = array_merge($imageSRCs, $postIntroImages, $postImages);


    }

    private function getPostImages($post)
    {
        $html=$post['content'];
        $doc = new \DOMDocument();
        @$doc->loadHTML($html);

        $tags = $doc->getElementsByTagName('img');
        $result=[];
        $rootDir = realpath($_SERVER["DOCUMENT_ROOT"]);
        foreach ($tags as $tag) {
            array_push($result,$rootDir.$tag->getAttribute('src'));
        }
        return $result;
    }
    private function getPostIntroImages($post)
    {
        $html=$post['introductory'];
        $doc = new \DOMDocument();
        @$doc->loadHTML($html);

        $tags = $doc->getElementsByTagName('img');
        $result=[];
        $rootDir = realpath($_SERVER["DOCUMENT_ROOT"]);
        foreach ($tags as $tag) {
            array_push($result,$rootDir.$tag->getAttribute('src'));
        }
        return $result;
    }

    protected function getNewsByPathOrFail()
    {
        $uri = explode('/', Request::path());

        return Item::findOrFail(end($uri));
    }

    /**
     * Sends a test newsletter to the logged user.
     */
    public function onTest()
    {
        $news   = $this->getNewsByPathOrFail();
        $sender = new NewsSender($news);

        if ($sender->sendTestNewsletter()) {
            Flash::success(trans('system::lang.mail_templates.test_success'));
        }
        else {
            Flash::error(trans('indikator.news::lang.flash.newsletter_test_error'));
        }
    }

    /**
     * Sends a newsletter the first time if last_send_at is null.
     * Flash message will be attached.
     *
     * @return mixed
     */
    public function onNewsSend()
    {
        $news = $this->getNewsByPathOrFail();

        if ($news->last_send_at === null) {
            $sender = new NewsSender($news);

            if ($sender->sendNewsletter()) {
                Flash::success(trans('indikator.news::lang.flash.newsletter_send_success'));
            }
            else {
                Flash::error(trans('indikator.news::lang.flash.newsletter_send_error'));
            }
        }
        else {
            Flash::error(trans('indikator.news::lang.flash.newsletter_send_error'));
        }

        return Redirect::refresh();
    }

    /**
     * Sends a newsletter again to the subscribers.
     * Returns a refresh with attached Flash message.
     *
     * @return mixed
     */
    public function onNewsResend()
    {
        $news   = $this->getNewsByPathOrFail();
        $sender = new NewsSender($news);

        if ($sender->resendNewsletter()) {
            Item::where('id', $news->id)->update(['last_send_at' => now()]);

            Flash::success(trans('indikator.news::lang.flash.newsletter_resend_success'));
        }
        else {
            Flash::error(trans('indikator.news::lang.flash.newsletter_resend_error'));
        }

        return Redirect::refresh();
    }

    public function onActivatePosts()
    {
        if ($this->isSelected()) {
            $this->changeStatus(post('checked'), 1);
            $this->setMessage('activate');
        }

        return $this->listRefresh();
    }

    public function onDeactivatePosts()
    {
        if ($this->isSelected()) {
            $this->changeStatus(post('checked'), 2);
            $this->setMessage('deactivate');
        }

        return $this->listRefresh();
    }

    public function onDraftPosts()
    {
        if ($this->isSelected()) {
            $this->changeStatus(post('checked'), 3);
            $this->setMessage('draft');
        }

        return $this->listRefresh();
    }

    public function onRemovePosts()
    {
        if ($this->isSelected()) {
            foreach (post('checked') as $itemId) {
                if (!$item = Item::whereId($itemId)) {
                    continue;
                }

                $item->delete();
            }

            $this->setMessage('remove');
        }

        return $this->listRefresh();
    }

    /**
     * @return bool
     */
    private function isSelected()
    {
        return ($checkedIds = post('checked')) && is_array($checkedIds) && count($checkedIds);
    }

    /**
     * @param $action
     */
    private function setMessage($action)
    {
        Flash::success(Lang::get('indikator.news::lang.flash.'.$action));
    }

    /**
     * @param $post
     * @param $id
     */
    private function changeStatus($post, $id)
    {
        foreach ($post as $itemId) {
            if (!$item = Item::where('status', '!=', $id)->whereId($itemId)) {
                continue;
            }

            if ($id == 1) {
                $update['status'] = 1;

                if (Item::whereId($itemId)->value('published_at') == null) {
                    $update['published_at'] = Carbon::now();
                }
            }
            else {
                $update = ['status' => $id];
            }

            $item->update($update);
        }
    }

    /**
     * @param $id
     */
    public function onClonePosts($id)
    {
        $post    = Item::find($id);
        $newPost = $post->duplicate($post);
        $path    = Request::path();

        return Redirect::to(substr($path, 0, strrpos($path, '/', -1) + 1).$newPost->id);
    }

    public function onShowImage()
    {
        $this->vars['title'] = Item::where('image', post('image'))->value('title');
        $this->vars['image'] = '/storage/app/media'.post('image');

        return $this->makePartial('show_image');
    }

    public function onShowStat()
    {
        $this->vars['post'] = $post = Item::whereId(post('id'))->first();
        $this->vars['last_send_at'] = ($post->last_send_at) ? $post->last_send_at : '<em>'.e(trans('indikator.news::lang.form.no_data')).'</em>';
        $this->vars['published_at'] = ($post->published_at) ? $post->published_at : '<em>'.e(trans('indikator.news::lang.form.no_data')).'</em>';

        return $this->makePartial('show_stat');
    }

    /**
     * Add user_id for user relationship before save
     *
     * @param $model
     */
    public function formBeforeCreate($model)
    {
        $model->user_id = $this->user->id;
    }
}
