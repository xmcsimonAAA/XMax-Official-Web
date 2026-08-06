<?php
namespace app\portal\service;

use think\facade\Db;
use think\facade\Log;
use cmf\controller\AdminBaseController;
use app\admin\service\EmailService;

/**
 * 文章邮件发送服务类
 * 专门处理文章发布时的邮件通知功能
 */
class ArticleEmailService
{
    /**
     * 批量发送文章邮件通知
     * @param array $articles 文章列表
     * @return array 发送结果统计
     */
    public static function batchSendArticleEmails($articles)
    {
        if (empty($articles)) {
            return ['success' => 0, 'total' => 0, 'failed' => 0];
        }
        
        // 获取所有订阅的邮箱地址
        $emails = self::getSubscribedEmails();
        
        if (empty($emails)) {
            Log::info('No subscribed emails found for article notification');
            return ['success' => 0, 'total' => 0, 'failed' => 0];
        }
        
        $totalArticles = count($articles);
        $totalEmails = count($emails);
        $successCount = 0;
        $failedEmails = [];
        
        try {
            // 为每篇文章发送邮件
            foreach ($articles as $article) {
                $subject = $article['post_title'];
                $content = $article['post_content'];
                
                // 优化：可以考虑使用队列或批处理方式发送邮件
                $result = self::sendEmailToSubscribers($emails, $subject, $content);
                
                if ($result['success']) {
                    $successCount++;
                    // 合并失败的邮箱记录
                    $failedEmails = array_merge($failedEmails, $result['failed_emails']);
                }
            }
            
            // 记录发送结果
            self::logEmailSendingResult($totalArticles, $totalEmails, $successCount, $failedEmails);
            
            return [
                'success' => $successCount,
                'total' => $totalArticles,
                'failed' => $totalArticles - $successCount,
                'failed_emails' => $failedEmails
            ];
        } catch (\Exception $e) {
            Log::error('Error in batch sending article emails: ' . $e->getMessage());
            return ['success' => 0, 'total' => $totalArticles, 'failed' => $totalArticles];
        }
    }
    
    /**
     * 获取所有订阅邮箱
     * @return array 邮箱地址列表
     */
    private static function getSubscribedEmails()
    {
        try {
            return Db::name('email')->column('email');
        } catch (\Exception $e) {
            Log::error('Error fetching subscribed emails: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * 发送邮件给所有订阅者
     * @param array $emails 邮箱列表
     * @param string $subject 邮件主题
     * @param string $content 邮件内容
     * @return array 发送结果
     */
    private static function sendEmailToSubscribers($emails, $subject, $content)
    {
        $success = true;
        $failedEmails = [];
        
        try {
            // 这里可以优化为使用邮件队列或批量发送机制
            foreach ($emails as $email) {
                try {
                    $decodedContent = htmlspecialchars_decode($content);
                    $result = EmailService::send($email, $subject, $decodedContent);
                    
                    if (isset($result['error']) && $result['error'] !== 0) {
                        $failedReason = isset($result['content']) ? $result['content'] : 'Unknown error';
                        $failedEmails[$email] = $failedReason;
                    }
                } catch (\Exception $e) {
                    $failedEmails[$email] = $e->getMessage();
                }
            }
            
            // 如果有超过一半的邮件发送失败，则认为整体失败
            if (count($failedEmails) > count($emails) / 2) {
                $success = false;
            }
        } catch (\Exception $e) {
            Log::error('Error sending email to subscribers: ' . $e->getMessage());
            $success = false;
        }
        
        return ['success' => $success, 'failed_emails' => $failedEmails];
    }
    
    /**
     * 记录邮件发送结果
     */
    private static function logEmailSendingResult($totalArticles, $totalEmails, $successCount, $failedEmails)
    {
        $logData = [
            'time' => date('Y-m-d H:i:s'),
            'total_articles' => $totalArticles,
            'total_emails' => $totalEmails,
            'success_articles' => $successCount,
            'failed_emails_count' => count($failedEmails),
            'failed_emails' => $failedEmails
        ];
        
        Log::info('Article email notification summary: ' . json_encode($logData), 'email');
        
        // 如果有发送失败的情况，单独记录错误日志
        if (!empty($failedEmails)) {
            Log::error('Failed email notifications: ' . json_encode($logData), 'email_failed');
        }
    }
}