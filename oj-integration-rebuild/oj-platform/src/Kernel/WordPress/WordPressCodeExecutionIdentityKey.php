<?php
declare(strict_types=1);
namespace CodingPanda\OjPlatform\Kernel\WordPress;

/** Platform-owned identity secret survives AUTH salt rotation; ticket signatures do not. */
final class WordPressCodeExecutionIdentityKey
{
    private const OPTION='oj_platform_private_execution_identity_key';
    public function key():string
    {
        if(!function_exists('get_option')||!function_exists('add_option')){throw new \RuntimeException('Execution identity storage unavailable.');}
        $record=get_option(self::OPTION,null);
        if($record===null){
            $auth=function_exists('wp_salt')?wp_salt('auth'):'';
            if(!is_string($auth)||strlen($auth)<32){throw new \RuntimeException('Execution identity seed unavailable.');}
            // Domain separation never copies the wp-config authentication secret to the DB.
            $candidate=hash_hmac('sha256','oj-platform:private-execution-identity:v1',$auth);
            add_option(self::OPTION,['format'=>1,'identity_key'=>$candidate],'',false);
            $record=get_option(self::OPTION,null); // Concurrent initializers use the winner.
        }
        if(!is_array($record)||($record['format']??null)!==1||!is_string($record['identity_key']??null)||strlen($record['identity_key'])<32){
            throw new \RuntimeException('Execution identity key is missing or damaged.');
        }
        return $record['identity_key'];
    }
}
