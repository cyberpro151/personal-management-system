<?php

namespace App\Services\System;

use App\DTO\DatabaseCredentialsDTO;
use App\Services\Utils;
use Exception;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class EnvReader extends AbstractController {

    const APP_DEFAULT_NPL_RECEIVER_EMAILS = "APP_DEFAULT_NPL_RECEIVER_EMAILS";

    const PENDO_TRACK_EVENT_SECRET = "PENDO_TRACK_EVENT_SECRET";
    const PENDO_DATA_HOST          = "PENDO_DATA_HOST";
    const PENDO_DEFAULT_DATA_HOST  = "data.pendo.io";

    public static function getUploadDir() {
        return $_ENV['UPLOAD_DIR'];
    }

    public static function getImagesUploadDir() {
        return $_ENV['IMAGES_UPLOAD_DIR'];
    }

    public static function getVideoUploadDir() {
        return $_ENV['VIDEOS_UPLOAD_DIR'];
    }

    public static function getMiniaturesUploadDir() {
        return $_ENV['MINIATURES_UPLOAD_DIR'];
    }

    public static function getPublicRootDir(){
        return $_ENV['PUBLIC_ROOT_DIR'];
    }

    public static function getFilesUploadDir() {
        return $_ENV['FILES_UPLOAD_DIR'];
    }

    public static function getDatabaseUrl() {
        return $_ENV['DATABASE_URL'];
    }

    /**
     * Will return the default emails receivers for all emails sent in NPL
     *
     * @return array
     * @throws Exception
     */
    public static function getNotifierProxyLoggerDefaultReceiversEmails(): array
    {
        $emailsString = Utils::getRealArrayForStringArray($_ENV[self::APP_DEFAULT_NPL_RECEIVER_EMAILS]);
        return $emailsString;
    }

    /**
     * Returns the Pendo integration key used for sending server-side Track Events,
     * it's a secret, so it must only be provided via environment (never committed),
     * Track Events are not sent at all when it's not set
     *
     * @return string|null
     */
    public static function getPendoTrackEventSecret(): ?string
    {
        $secret = $_ENV[self::PENDO_TRACK_EVENT_SECRET] ?? $_SERVER[self::PENDO_TRACK_EVENT_SECRET] ?? null;
        return empty($secret) ? null : $secret;
    }

    /**
     * Returns the Pendo host (without protocol) that receives the server-side Track Events
     *
     * @return string
     */
    public static function getPendoDataHost(): string
    {
        $host = $_ENV[self::PENDO_DATA_HOST] ?? $_SERVER[self::PENDO_DATA_HOST] ?? null;
        return empty($host) ? self::PENDO_DEFAULT_DATA_HOST : $host;
    }

    /**
     * @return DatabaseCredentialsDTO
     * @throws Exception
     */
    public static function getDatabaseCredentials(){
        $regex       = '#^mysql:\/\/(.*):(.*)@([^:]*)([:])?(.*)\/(.*)#';
        $databaseUrl = self::getDatabaseUrl();

        preg_match($regex, $databaseUrl, $matches);

        try{

            $login        = $matches[1] ?? "";
            $password     = $matches[2] ?? "";
            $host         = $matches[3] ?? "";
            $port         = $matches[5] ?? "";
            $databaseName = $matches[6] ?? "";

        }catch(\Exception $e){
            throw new Exception("There was ane error while parsing database connection from .env. {$e->getMessage()}");
        }

        $dto = new DatabaseCredentialsDTO();
        $dto->setDatabaseLogin($login);
        $dto->setDatabaseHost($host);
        $dto->setDatabasePassword($password);
        $dto->setDatabasePort($port);
        $dto->setDatabaseName($databaseName);

        return $dto;
    }

    /**
     * @return bool
     */
    public static function isDemo() {
        try {
            $isDemo = Utils::getBoolRepresentationOfBoolString($_ENV['APP_DEMO']);
        } catch (\Exception $e) {
            $isDemo = false;
        }
        return $isDemo;
    }

    /**
     * Check if the project is running in dev mode
     *
     * @return bool
     */
    public static function isDev(): bool
    {
        return (strtolower(self::getEnvironment()) == "dev");
    }

    /**
     * Returns current environment under which the project is running
     *
     * @return string
     */
    public static function getEnvironment(): string
    {
        return $_ENV['APP_ENV'];
    }

}
