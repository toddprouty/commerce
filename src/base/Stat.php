<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */
namespace craft\commerce\base;

use Craft;
use craft\commerce\db\Table;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use yii\base\Exception;

/**
 * Stat
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 3.0
 */
abstract class Stat implements StatInterface
{
    // Properties
    // =========================================================================

    use StatTrait;

    // Public Methods
    // =========================================================================

    /**
     * Stat constructor.
     *
     * @param string $dateRange
     * @param null $startDate
     * @param null $endDate
     * @throws \Exception
     */
    public function __construct(string $dateRange = null, $startDate = null, $endDate = null)
    {
        $this->dateRange = $dateRange;
        $this->setStartDate($startDate);
        $this->setEndDate($endDate);

        if ($this->dateRange) {
            $this->_setDates();
        }

        $user = Craft::$app->getUser()->getIdentity();
        if ($user) {
            $this->weekStartDay = $user->getPreference('weekStartDay');
        }
    }

    /**
     * @return array|false|mixed|null
     * @throws Exception
     */
    public function get()
    {
        $this->_setDates();

        if (!$this->cache) {
            return $this->getData();
        }

        $this->_cacheKey = $this->_getCacheKey();

        if (!$this->_cacheKey) {
            throw new Exception('Unable to create cache key.');
        }

        $data = Craft::$app->getCache()->get($this->_cacheKey);

        if (!$data) {
            $data = $this->getData();
            Craft::$app->getCache()->set($this->_cacheKey, $data, $this->cacheDuration);
        }

        return $data;
    }

    /**
     * @inheritDoc
     */
    public function setStartDate($date)
    {
        $this->_startDate = $date;
    }

    /**
     * @inheritDoc
     */
    public function setEndDate($date)
    {
        $this->_endDate = $date;
    }

    /**
     * @inheritDoc
     */
    public function getStartDate()
    {
        return $this->_startDate;
    }

    /**
     * @inheritDoc
     */
    public function getEndDate()
    {
        return $this->_endDate;
    }

    // Private Methods
    // =========================================================================

    /**
     * @throws Exception
     */
    private function _setDates(): void
    {
        if (!$this->dateRange) {
            throw new Exception('A date range string must be specified to set stat dates.');
        }

        if ($this->_startDate && $this->_endDate) {
            return;
        }

        if ($this->dateRange != self::DATE_RANGE_CUSTOM) {
            $this->setStartDate($this->_getStartDate($this->dateRange));
            $this->setEndDate($this->_getEndDate($this->dateRange));
        }
    }
    /**
     * Based on the date range return the start date.
     *
     * @param string $dateRange
     * @return bool|\DateTime|false
     * @throws \Exception
     */
    private function _getStartDate(string $dateRange)
    {
        if ($dateRange == self::DATE_RANGE_CUSTOM) {
            return false;
        }

        $date = new \DateTime();
        switch ($dateRange) {
            case self::DATE_RANGE_THISMONTH:
            {
                $date = DateTimeHelper::toDateTime(strtotime('first day of this month'));
                break;
            }
            case self::DATE_RANGE_THISWEEK:
            {
                if (date('l') != self::START_DAY_INT_TO_DAY[$this->weekStartDay]) {
                    $date = DateTimeHelper::toDateTime(strtotime('last ' . self::START_DAY_INT_TO_DAY[$this->weekStartDay]));
                }
                break;
            }
            case self::DATE_RANGE_THISYEAR:
            {
                $date->setDate($date->format('Y'), 1, 1);
                break;
            }
            case self::DATE_RANGE_PAST7DAYS:
            case self::DATE_RANGE_PAST30DAYS:
            case self::DATE_RANGE_PAST90DAYS:
            {
                $numDays = str_replace(['past', 'Days'], ['P','D'], $dateRange);
                $date = $this->_getEndDate($dateRange);
                $interval = new \DateInterval($numDays);
                $date->sub($interval);
                break;
            }
            case self::DATE_RANGE_PASTYEAR:
            {
                $date = $this->_getEndDate($dateRange);
                $interval = new \DateInterval('P1Y');
                $date->sub($interval);
                $date->add(new \DateInterval('P1D'));
                break;

            }
        }

        $date->setTime(0, 0, 0);
        return $date;
    }

    /**
     * Based on the date range return the end date.
     *
     * @param string $dateRange
     * @return bool|\DateTime|false
     * @throws \Exception
     */
    private function _getEndDate(string $dateRange)
    {
        if ($dateRange == self::DATE_RANGE_CUSTOM) {
            return false;
        }

        $date = new \DateTime();
        switch ($dateRange) {
            case self::DATE_RANGE_THISMONTH:
            {
                $date = DateTimeHelper::toDateTime(strtotime('last day of this month'));
                break;
            }
            case self::DATE_RANGE_THISWEEK:
            {
                $endDayOfWeek = self::START_DAY_INT_TO_END_DAY[$this->weekStartDay];
                if (date('l') != $endDayOfWeek) {
                    $date = DateTimeHelper::toDateTime(strtotime('next ' . $endDayOfWeek));
                }
                break;
            }
        }

        $date->setTime(23, 59, 59);
        return $date;
    }

    /**
     * Generate cache key.
     *
     * @return string|null
     * @throws \Exception
     */
    private function _getCacheKey(): ?string
    {
        $orderLastUpdatedString = 'never';

        $orderLastUpdated = $this->_createStatQuery()
            ->select(['dateUpdated'])
            ->orderBy('dateUpdated DESC')
            ->scalar();

        if ($orderLastUpdated) {
            $orderLastUpdated = DateTimeHelper::toDateTime($orderLastUpdated);
            $orderLastUpdatedString = $orderLastUpdated->format('Y-m-d-H-i-s');
        }

        return implode('-', [$this->handle, $this->dateRange, $orderLastUpdatedString]);
    }

    // Protected Methods
    // =========================================================================

    /**
     * Generate base stat query
     *
     * @return \yii\db\Query
     */
    protected function _createStatQuery()
    {
        return (new Query)
            ->from(Table::ORDERS . ' orders')
            ->where(['>=', 'dateOrdered', Db::prepareDateForDb($this->_startDate)])
            ->andWhere(['<=', 'dateOrdered', Db::prepareDateForDb($this->_endDate)])
            ->andWhere(['isCompleted' => 1]);
    }
}