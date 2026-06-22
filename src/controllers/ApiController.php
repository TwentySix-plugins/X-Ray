<?php

namespace twentysix\xray\controllers;

use twentysix\xray\twigextensions\Extension;
use Craft;
use craft\web\Controller;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

class ApiController extends Controller
{
    protected array|bool|int $allowAnonymous = false;

    /**
     * GET /admin/x-ray/api/component-props?cid=xr_…
     * Returns the cached props for a wrapped component (lazy-loaded by the
     * viewer instead of being inlined into the page's HTML).
     *
     * Note: the param is `cid`, NOT `token` — Craft reserves the `token` query
     * param for its own signed-route tokens and would reject the request.
     */
    public function actionComponentProps(): Response
    {
        $currentUser = Craft::$app->getUser()->getIdentity();
        if (!$currentUser || !$currentUser->admin) {
            throw new ForbiddenHttpException('Admin access required.');
        }

        $this->requireAcceptsJson();

        $token = (string)Craft::$app->getRequest()->getRequiredParam('cid');
        if (!preg_match('/^xr_[0-9a-f]{1,40}$/', $token)) {
            return $this->asJson(['error' => 'Invalid token.']);
        }

        $json = Craft::$app->getCache()->get(Extension::PROPS_CACHE_PREFIX . $token);
        if ($json === false) {
            // Cache entry expired or the page hasn't been (re)rendered since.
            return $this->asJson(['expired' => true]);
        }

        return $this->asJson(['props' => json_decode($json, true)]);
    }

    /**
     * GET /admin/x-ray/api/global-sets
     * Returns a list of all Global Sets for the current site with their fields.
     */
    public function actionGlobalSets(): Response
    {
        $currentUser = Craft::$app->getUser()->getIdentity();
        if (!$currentUser || !$currentUser->admin) {
            throw new ForbiddenHttpException('Admin access required.');
        }

        $this->requireAcceptsJson();

        $sets = Craft::$app->getGlobals()->getAllSets();

        // Cache the serialized payload (the expensive part is loading every
        // field value + serializing). The key embeds each set's id and
        // dateUpdated, so editing any global's content changes the key and
        // serves fresh data automatically; the TTL is just a safety net.
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $stamp = '';
        foreach ($sets as $set) {
            $stamp .= $set->id . ':' . ($set->dateUpdated?->getTimestamp() ?? 0) . ';';
        }
        $cacheKey = 'x-ray:global-sets:' . $siteId . ':' . md5($stamp);
        $cache = Craft::$app->getCache();

        $result = $cache->get($cacheKey);
        if ($result === false) {
            $result = [];

            foreach ($sets as $set) {
                $fields = [];
                foreach ($set->getFieldValues() as $handle => $value) {
                    $field = Craft::$app->getFields()->getFieldByHandle($handle);
                    $fields[] = [
                        'handle' => $handle,
                        'name'   => $field?->name ?? $handle,
                        'type'   => $field ? (new \ReflectionClass($field))->getShortName() : 'Unknown',
                        'value'  => $this->serializeFieldValue($value),
                    ];
                }

                $result[] = [
                    'id'      => $set->id,
                    'name'    => $set->name,
                    'handle'  => $set->handle,
                    'editUrl' => $set->getCpEditUrl(),
                    'fields'  => $fields,
                ];
            }

            $cache->set($cacheKey, $result, 3600);
        }

        return $this->asJson(['globalSets' => $result]);
    }

    private function serializeFieldValue(mixed $value): mixed
    {
        $settings = \twentysix\xray\Plugin::getInstance()->getSettings();
        $extension = new Extension($settings);
        return $extension->sanitizeValue($value);
    }
}
