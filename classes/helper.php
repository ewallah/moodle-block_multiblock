<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Supporting infrastructure for the multiblock editing.
 *
 * @package   block_multiblock
 * @copyright 2019 Peter Spicer <peter.spicer@catalyst-eu.net>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_multiblock;

use context;
use context_block;

defined('MOODLE_INTERNAL') || die();

/**
 * Supporting infrastructure for the multiblock editing.
 *
 * @package   block_multiblock
 * @copyright 2019 Peter Spicer <peter.spicer@catalyst-eu.net>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class helper {

    /**
     * Provide some functionality for bootstrapping the page for a given block.
     *
     * Namely: load the block instance, some $PAGE setup, navigation setup.
     *
     * @param int $blockid The block ID being operated on.
     * @return array Return the block record and its instance class.
     */
    public static function bootstrap_page($blockid) {
        global $DB, $PAGE;

        $blockctx = context_block::instance($blockid);
        $block = $DB->get_record('block_instances', ['id' => $blockid], '*', MUST_EXIST);
        if (block_load_class($block->blockname)) {
            $class = 'block_' . $block->blockname;
            $blockinstance = new $class;
            $blockinstance->_load_instance($block, $PAGE);
        }

        $PAGE->set_context($blockctx);
        $PAGE->set_url($blockctx->get_url());
        $PAGE->set_pagelayout('admin');

        return [$block, $blockinstance];
    }

    /**
     * Finds the parent non-block context for a given block.
     * For example, a dashboard can hook off the user context
     * for which the dashboard is created, which contains the
     * multiblock context, which under it will contain the
     * child blocks. This, given the child block id will
     * traverse the parents in order until it hits the closest
     * ancestor that is not a block context.
     *
     * @param int $blockid
     * @return object A context object reflecting the nearest ancestor
     */
    public static function find_nearest_nonblock_ancestor($blockid) {
        global $DB;

        $context = $DB->get_record('context', ['instanceid' => $blockid, 'contextlevel' => CONTEXT_BLOCK]);
        // Convert the path from /1/2/3 to [1, 2, 3], remove the leading empty item and this item.
        $path = explode('/', $context->path);
        $path = array_diff($path, ['', $context->id]);
        foreach (array_reverse($path) as $contextid) {
            $parentcontext = $DB->get_record('context', ['id' => $contextid]);
            if ($parentcontext->contextlevel != CONTEXT_BLOCK) {
                // We found the one we care about.
                return context::instance_by_id($parentcontext->id);
            }
        }

        throw new coding_exception('Could not find parent non-block ancestor for block id ' . $blockid);
    }

    /**
     * Splits a subblock out of the multiblock and returns it to the
     * parent context that the parent multiblock lives in.
     *
     * @param object $parent The parent instance object, from block_instances table.
     * @param int $childid The id of the subblock to remove, from block_instances table.
     */
    public static function split_block($parent, $childid) {
        global $DB;

        // Get the block details and the target context to move it to.
        $subblock = $DB->get_record('block_instances', ['id' => $childid]);
        $parentcontext = static::find_nearest_nonblock_ancestor($childid);

        // Copy some parameters from the parent since that's what we're using now.
        $params = [
            'showinsubcontexts', 'requiredbytheme', 'pagetypepattern', 'subpagepattern',
            'defaultregion', 'defaultweight',
        ];
        foreach ($params as $param) {
            $subblock->$param = $parent->$param;
        }

        // Then set up the parts that aren't inherited from the old parent, and commit.
        $subblock->parentcontextid = $parentcontext->id;
        $subblock->timemodified = time();
        $DB->update_record('block_instances', $subblock);

        // Now fix the position to mirror the parent, if it has one.
        $parentposition = $DB->get_record('block_positions', [
            'contextid' => $parentcontext->id,
            'blockinstanceid' => $parent->id,
        ], '*', IGNORE_MISSING);
        if ($parentposition) {
            // The parent has a specific position, we need to add that.
            $newchild = $parentposition;
            $newchild->blockinstanceid = $childid;
            $DB->insert_record('block_positions', $newchild);
        }

        // Finally commit the updated context path to this block.
        $childcontext = context_block::instance($childid);
        $childcontext->update_moved($parentcontext);
    }
}
