<?php

/**
 * @package     JoomlaDependencies
 * @license     GNU General Public License version 3 or later
 *
 * The "Dependencies" tab on System > Install > Extensions.
 *
 * @var  \JoomlaDependencies\Plugin\System\Dependencies\Site\Overview  $overview
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use JoomlaDependencies\Plugin\System\Dependencies\Extension\ProblemText;

?>
<fieldset class="options-form">
    <legend><?php echo Text::_('PLG_SYSTEM_DEPENDENCIES_TAB_DECLARED'); ?></legend>
    <?php if ($overview->requirements === []) : ?>
        <p><?php echo Text::_('PLG_SYSTEM_DEPENDENCIES_TAB_NONE_DECLARED'); ?></p>
    <?php else : ?>
        <table class="table table-sm">
            <thead>
                <tr>
                    <th scope="col"><?php echo Text::_('PLG_SYSTEM_DEPENDENCIES_TAB_EXTENSION'); ?></th>
                    <th scope="col"><?php echo Text::_('PLG_SYSTEM_DEPENDENCIES_TAB_NEEDS'); ?></th>
                    <th scope="col"><?php echo Text::_('PLG_SYSTEM_DEPENDENCIES_TAB_STATUS'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($overview->requirements as $row) : ?>
                    <tr>
                        <td><?php echo htmlspecialchars($row['extension'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><code><?php echo htmlspecialchars($row['requirement'], ENT_QUOTES, 'UTF-8'); ?></code></td>
                        <td>
                            <?php if ($row['problem'] === null) : ?>
                                <span class="badge bg-success"><?php echo Text::_('PLG_SYSTEM_DEPENDENCIES_TAB_MET'); ?></span>
                            <?php else : ?>
                                <span class="badge bg-danger"><?php echo Text::_('PLG_SYSTEM_DEPENDENCIES_TAB_NOT_MET'); ?></span>
                                <?php echo htmlspecialchars(ProblemText::of($row['problem']), ENT_QUOTES, 'UTF-8'); ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</fieldset>

<fieldset class="options-form">
    <legend><?php echo Text::_('PLG_SYSTEM_DEPENDENCIES_TAB_LOCKED'); ?></legend>
    <p><?php echo Text::_('PLG_SYSTEM_DEPENDENCIES_TAB_LOCKED_DESC'); ?></p>
    <?php if ($overview->locked === []) : ?>
        <p><?php echo Text::_('JNONE'); ?></p>
    <?php else : ?>
        <table class="table table-sm">
            <thead>
                <tr>
                    <th scope="col"><?php echo Text::_('PLG_SYSTEM_DEPENDENCIES_TAB_EXTENSION'); ?></th>
                    <th scope="col"><?php echo Text::_('PLG_SYSTEM_DEPENDENCIES_TAB_NEEDED_BY'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($overview->locked as $row) : ?>
                    <tr>
                        <td><?php echo htmlspecialchars($row['extension'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars(implode(', ', $row['neededBy']), ENT_QUOTES, 'UTF-8'); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</fieldset>
