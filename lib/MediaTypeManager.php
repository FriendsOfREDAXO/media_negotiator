<?php

namespace FriendsOfRedaxo\MediaNegotiator;

use Exception;
use rex;
use rex_addon;
use rex_media_manager;
use rex_sql;

/**
 * Manages the assignment of the negotiator effect to existing media manager types.
 */
class MediaTypeManager
{
    private const EFFECT_NAME = 'negotiator';

    /**
     * Returns all media manager types with their negotiator-effect status.
     *
     * @return list<array{id: int, name: string, description: string, has_effect: bool, priority: int, total_effects: int}>
     */
    public static function getTypesWithStatus(): array
    {
        $sql = rex_sql::factory();

        /** @var list<array{id: string|int, name: string, description: string}> $types */
        $types = $sql->getArray(
            'SELECT id, name, description FROM ' . rex::getTable('media_manager_type') . ' ORDER BY name'
        );

        $result = [];
        foreach ($types as $type) {
            $typeId = (int) $type['id'];

            /** @var list<array{priority: string|int}> $negotiatorRows */
            $negotiatorRows = $sql->getArray(
                'SELECT priority FROM ' . rex::getTable('media_manager_type_effect') .
                ' WHERE type_id = :id AND effect = :effect ORDER BY priority',
                ['id' => $typeId, 'effect' => self::EFFECT_NAME]
            );

            /** @var list<array{cnt: string|int}> $countRows */
            $countRows = $sql->getArray(
                'SELECT COUNT(*) AS cnt FROM ' . rex::getTable('media_manager_type_effect') .
                ' WHERE type_id = :id',
                ['id' => $typeId]
            );

            $result[] = [
                'id'            => $typeId,
                'name'          => (string) $type['name'],
                'description'   => (string) $type['description'],
                'has_effect'    => count($negotiatorRows) > 0,
                'priority'      => count($negotiatorRows) > 0 ? (int) $negotiatorRows[0]['priority'] : 0,
                'total_effects' => count($countRows) > 0 ? (int) $countRows[0]['cnt'] : 0,
            ];
        }

        return $result;
    }

    /**
     * Adds the negotiator effect to a media type.
     *
     * @param string $position 'append' (end of chain) or 'prepend' (beginning of chain)
     */
    public static function addEffect(int $typeId, string $position = 'append'): void
    {
        $sql = rex_sql::factory();

        // Skip if effect already assigned
        /** @var list<array{id: string|int}> $existing */
        $existing = $sql->getArray(
            'SELECT id FROM ' . rex::getTable('media_manager_type_effect') .
            ' WHERE type_id = :id AND effect = :effect',
            ['id' => $typeId, 'effect' => self::EFFECT_NAME]
        );
        if (count($existing) > 0) {
            return;
        }

        if ($position === 'prepend') {
            // Shift all existing effects up by 1
            $sql->setQuery(
                'UPDATE ' . rex::getTable('media_manager_type_effect') .
                ' SET priority = priority + 1 WHERE type_id = :id',
                ['id' => $typeId]
            );
            $priority = 1;
        } else {
            /** @var list<array{max_p: string|int|null}> $maxRows */
            $maxRows  = $sql->getArray(
                'SELECT MAX(priority) AS max_p FROM ' . rex::getTable('media_manager_type_effect') .
                ' WHERE type_id = :id',
                ['id' => $typeId]
            );
            $priority = (int) ($maxRows[0]['max_p'] ?? 0) + 1;
        }

        $sql->setTable(rex::getTable('media_manager_type_effect'));
        $sql->setValue('type_id', $typeId);
        $sql->setValue('effect', self::EFFECT_NAME);
        $sql->setValue('priority', $priority);
        $sql->setValue('parameters', json_encode(['rex_effect_' . self::EFFECT_NAME => []]));
        $sql->addGlobalCreateFields();
        $sql->insert();
    }

    /**
     * Removes the negotiator effect from a media type.
     */
    public static function removeEffect(int $typeId): void
    {
        $sql = rex_sql::factory();
        $sql->setQuery(
            'DELETE FROM ' . rex::getTable('media_manager_type_effect') .
            ' WHERE type_id = :id AND effect = :effect',
            ['id' => $typeId, 'effect' => self::EFFECT_NAME]
        );
    }

    /**
     * Processes a bulk action from the types page.
     *
     * @param list<int>  $typeIds
     * @param string     $position 'append' or 'prepend'
     * @return array{added: int, removed: int, skipped: int, error: string}
     */
    public static function bulkAdd(array $typeIds, string $position = 'append'): array
    {
        if (!rex_addon::get('media_manager')->isAvailable()) {
            return ['added' => 0, 'removed' => 0, 'skipped' => 0, 'error' => 'media_manager not available'];
        }

        $added   = 0;
        $skipped = 0;

        foreach ($typeIds as $typeId) {
            $typeId = (int) $typeId;
            if ($typeId <= 0) {
                continue;
            }
            // Check if already has effect
            $sql = rex_sql::factory();
            /** @var list<array{id: string|int}> $existing */
            $existing = $sql->getArray(
                'SELECT id FROM ' . rex::getTable('media_manager_type_effect') .
                ' WHERE type_id = :id AND effect = :effect',
                ['id' => $typeId, 'effect' => self::EFFECT_NAME]
            );
            if (count($existing) > 0) {
                ++$skipped;
                continue;
            }
            try {
                self::addEffect($typeId, $position);
                ++$added;
            } catch (Exception) {
                // continue on single-row failure
            }
        }

        rex_media_manager::deleteCache();
        return ['added' => $added, 'removed' => 0, 'skipped' => $skipped, 'error' => ''];
    }

    /**
     * @param list<int> $typeIds
     * @return array{added: int, removed: int, skipped: int, error: string}
     */
    public static function bulkRemove(array $typeIds): array
    {
        if (!rex_addon::get('media_manager')->isAvailable()) {
            return ['added' => 0, 'removed' => 0, 'skipped' => 0, 'error' => 'media_manager not available'];
        }

        $removed = 0;
        $skipped = 0;

        foreach ($typeIds as $typeId) {
            $typeId = (int) $typeId;
            if ($typeId <= 0) {
                continue;
            }
            $sql = rex_sql::factory();
            /** @var list<array{id: string|int}> $existing */
            $existing = $sql->getArray(
                'SELECT id FROM ' . rex::getTable('media_manager_type_effect') .
                ' WHERE type_id = :id AND effect = :effect',
                ['id' => $typeId, 'effect' => self::EFFECT_NAME]
            );
            if (count($existing) === 0) {
                ++$skipped;
                continue;
            }
            try {
                self::removeEffect($typeId);
                ++$removed;
            } catch (Exception) {
                // continue
            }
        }

        rex_media_manager::deleteCache();
        return ['added' => 0, 'removed' => $removed, 'skipped' => $skipped, 'error' => ''];
    }
}
