<?php

namespace Solspace\Calendar\Services;

use craft\base\Component;
use Solspace\Calendar\Elements\EventOverride;
use yii\base\Exception;

class OverridesService extends Component
{

    /**
     * @throws \Throwable
     * @throws Exception
     * @throws \yii\db\Exception
     */
    public function saveOverride(EventOverride $event, bool $validateContent = true): bool
    {
        $event->validate();

        $transaction = \Craft::$app->db->beginTransaction();

        try {
            $isSaved = \Craft::$app->elements->saveElement($event, $validateContent);
            if (!$isSaved) {
                return false;
            }

            if (null !== $transaction) {
                $transaction->commit();
            }

            return true;
        } catch (\Exception $e) {
            if (null !== $transaction) {
                $transaction->rollBack();
            }

            throw $e;
        }

        return false;
    }

    public function getOverrideForDate(int $eventId, \DateTime $date, ?int $siteId = null): ?EventOverride
    {
        $override = EventOverride::find()
            ->siteId($siteId)
            ->eventId($eventId)
            ->date($date)
            ->one();

        if (empty($override)) {
            return null;
        }

        return $override;
    }
}
