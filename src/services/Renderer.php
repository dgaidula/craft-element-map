<?php
/**
 * Element Map plugin for Craft 3.0
 * @copyright Copyright Charlie Development
 */

namespace charliedev\elementmap\services;

use Craft;
use craft\commerce\elements\db\ProductQuery;
use craft\commerce\elements\db\VariantQuery;
use craft\db\Query;
use craft\elements\db\AssetQuery;
use craft\elements\db\CategoryQuery;
use craft\elements\db\EntryQuery;
use craft\elements\db\GlobalSetQuery;
use craft\elements\db\TagQuery;
use craft\elements\db\UserQuery;
use craft\helpers\UrlHelper;

use yii\base\Component;

class Renderer extends Component
{
	/**
	 * @deprecated
	 */
	public function render(int $elementid, int $siteid)
	{
		Craft::$app->getDeprecator()->log('charliedev\elementmap\services\Renderer::render()', 'charliedev\elementmap\services\Renderer::render() is deprecated. Map results may be gathered individually with other functions such as getElementMap, getIncomingElements, and getOutgoingElements.');

		// Gather up necessary structure data to render the element map with.
		$results = $this->getElementMap($elementid, $siteid);

		// Render the actual element map.
		return Craft::$app->view->renderTemplate('element-map/_map', ['map' => $results]);
	}

	/**
	 * Generates a data structure containing elements that reference the given
	 * element and those that the given element references.
	 * @param int $elementid The ID of the element to retrieve map
	 * information about.
	 * @param int $siteId The ID of the site context that information should
	 * be gathered within.
	 */
	public function getElementMap($elementId, int $siteId)
	{
		if (!$elementId) {
			return null;
		}

		return [
			'incoming' => $this->getIncomingElements($elementId, $siteId),
			'outgoing' => $this->getOutgoingElements($elementId, $siteId),
		];
	}

	/**
	 * Retrieves a list of elements referencing the given element.
	 * @param int $elementid The ID of the element to retrieve map
	 * information about.
	 * @param int $siteId The ID of the site context that information should
	 * be gathered within.
	 */
	public function getIncomingElements($elementId, int $siteId)
	{
		if (!$elementId) {
			return null;
		}

		// Find incoming relationships to this element. Check for references to it, then trace those elements'
		// owners until we get to meaningful things, such as Category -in-> Matrix Block -in-> Element.
		$variants = $this->getVariantsByProduct($elementId); // Element -> variants.
		$fromdata = $this->getRelationshipGroups(array_merge([$elementId], $variants), $siteId, true);

		// Convert the retrieved element ids into data we use to display the map.
		$this->processRelationshipGroups($fromdata, $siteId);

		return $fromdata['results'];
	}

	/**
	 * Retrieves a list of elements that the given element references.
	 * @param int $elementid The ID of the element to retrieve map
	 * information about.
	 * @param int $siteId The ID of the site context that information should
	 * be gathered within.
	 */
	public function getOutgoingElements($elementId, int $siteId)
	{
		if (!$elementId) {
			return null;
		}

		// Find outgoing relationships from this element. This includes not only direct references, but any
		// child elements like matrix blocks must have their own external references included, this means we can
		// check things like Entry -contains-> Matrix Block -contains-> Asset.
		$variants = $this->getVariantsByProduct($elementId); // Element -> variants.
		$blocks = $this->getMatrixBlocksByOwner($elementId); // Element -> matrix blocks.
		$todata = $this->getRelationshipGroups(array_merge([$elementId], $blocks, $variants), $siteId, false); // Element + other nested content -> relationships

		// Convert the retrieved element ids into data we use to display the map.
		$this->processRelationshipGroups($todata, $siteId);

		return $todata['results'];
	}

	private function getVariantsByProduct($element) {
		if (!class_exists(VariantQuery::class)) { // If commerce is not installed, don't worry about variants.
			return [];
		}
		$conditions = [
			'and',
			['productId' => $element],
		];
		return (new Query())
			->select('id')
			->from('{{%commerce_variants}}')
			->where($conditions)
			->column();
	}

	/**
	 * @param array $elementids The array of elements to get relationships for.
	 * @param int $siteid The site ID that relationships should exist within.
	 * @param bool $getsources Set to true when the elementids are for target elements, and the sources are being
	 * searched for, or false when the elementids are for source elements, and the targets are being looked for.
	 */
	private function getRelationshipGroups(array $elementids, int $siteid, bool $getsources)
	{
		if ($getsources) {
			$fromcol = 'targetId';
			$tocol = 'sourceId';
		} else {
			$fromcol = 'sourceId';
			$tocol = 'targetId';
		}

		// Get a list of elements where the given element IDs are part of the relationship,
		// either target or source, defined by `getsources`.
		$conditions = [
			'and',
			[
				'in',
				$fromcol,
				$elementids
			],
			[
				'or',
				['sourceSiteId' => null],
				['sourceSiteId' => $siteid],
			],
		];

		$results = (new Query())
			->select('[[r.' . $tocol . ']] AS id, [[e.type]] AS type')
			->from('{{%relations}} r')
			->leftJoin('{{%elements}} e', '[[r.' . $tocol . ']] = [[e.id]]')
			->where($conditions)
			->all();

		// Create element type groups in order to further process the element list.
		$elements = [
			'craft\elements\MatrixBlock' => [],
			'craft\elements\Entry' => [],
			'craft\elements\GlobalSet' => [],
			'craft\elements\Category' => [],
			'craft\elements\Tag' => [],
			'craft\elements\Asset' => [],
			'craft\elements\User' => [],
			'craft\commerce\elements\Product' => [],
			'craft\commerce\elements\Variant' => [],
			'Other' => [],
			'results' => [],
		];
		$this->integrateGroupData($elements, $results);
		return $elements;
	}

	/**
	 * Processes input elements from a database query, and sorts them by type into an appropriate
	 * container for further processing.
	 * @param array &$groups A reference to the groups container to store elements within.
	 * @param array &elements The list of elements to store within the container.
	 */
	private function integrateGroupData(array &$groups, array &$elements) {
		foreach ($elements as $element) {
			if (isset($groups[$element['type']])) { // We know the type of element this is, store it.
				$groups[$element['type']][] = $element['id'];
			} else { // Some kind of element not handled by the map, store it in `Other`.
				$groups['Other'][] = $element['id'];
			}
		}
	}

	/**
	 * Retrieves a list of matrix block IDs based on the given owner ids.
	 * @param owners The owner ID to retrieve matrix blocks for.
	 */
	private function getMatrixBlocksByOwner($owner) {
		$conditions = [
			'and',
			['ownerId' => $owner],
			[
				'or',
				['ownerSiteId' => null],
				['ownerSiteId' => Craft::$app->getSites()->currentSite->id],
			]
		];
		return (new Query())
			->select('id')
			->from('{{%matrixblocks}}')
			->where($conditions)
			->column();
	}

	/**
	 * Finds owner elements of matrix group items, and returns those elements.
	 * @param group A list of matrix block ids to find owners for.
	 */
	private function processMatrixGroup($group) {
		$conditions = [
			'mb.id' => $group,
		];
		return (new Query())
			->select('[[e.id]] AS id, [[e.type]] AS type')
			->from('{{%matrixblocks}} mb')
			->leftJoin('{{%elements}} e', '[[mb.ownerId]] = [[e.id]]')
			->where($conditions)
			->all();
	}

	/**
	 * Converts entries into a list of standardized result items.
	 * @param group The IDs of the entries to convert.
	 */
	private function processEntryGroup($group, $siteId) {
		$criteria = new EntryQuery('craft\elements\Entry');
		$criteria->id = $group;
		$criteria->siteId = $siteId;
		$elements = $criteria->all();

		$results = [];
		foreach ($elements as $element) {
			$results[] = [
				'id' => $element->id,
				'icon' => '@vendor/craftcms/cms/src/icons/newspaper.svg',
				'title' => $element->title,
				'url' => $element->cpEditUrl,
			];
		}
		return $results;
	}

	/**
	 * Converts global set items into a list of standardized result items.
	 * @param group The IDs of the global sets to convert.
	 */
	private function processGlobalSetGroup($group) {
		$criteria = new GlobalSetQuery('craft\elements\GlobalSet');
		$criteria->id = $group;
		$elements = $criteria->all();

		$results = [];
		foreach ($elements as $element) {
			$results[] = [
				'id' => $element->id,
				'icon' => '@vendor/craftcms/cms/src/icons/globe.svg',
				'title' => $element->name,
				'url' => $element->cpEditUrl,
			];
		}
		return $results;
	}

	/**
	 * Converts categories into a list of standardized result items.
	 * @param group The IDs of the categories to convert.
	 */
	private function processCategoryGroup($group, $siteId) {
		$criteria = new CategoryQuery('craft\elements\Category');
		$criteria->id = $group;
		$criteria->siteId = $siteId;
		$elements = $criteria->all();

		$results = [];
		foreach ($elements as $element) {
			$results[] = [
				'id' => $element->id,
				'icon' => '@vendor/craftcms/cms/src/icons/folder-open.svg',
				'title' => $element->title,
				'url' => $element->cpEditUrl,
			];
		}
		return $results;
	}

	/**
	 * Converts tags into a list of standardized result items.
	 * @param group The IDs of the tags to convert.
	 */
	private function processTagGroup($group) {
		$criteria = new TagQuery('craft\elements\Tag');
		$criteria->id = $group;
		$elements = $criteria->all();

		$results = [];
		foreach ($elements as $element) {
			$results[] = [
				'id' => $element->id,
				'icon' => '@vendor/craftcms/cms/src/icons/tags.svg',
				'title' => $element->title,
				'url' => '/' . Craft::$app->getConfig()->getGeneral()->cpTrigger . '/settings/tags/' . $element->groupId,
			];
		}
		return $results;
	}

	/**
	 * Converts assets into a list of standardized result items.
	 * @param group The IDs of the assets to convert.
	 */
	private function processAssetGroup($group) {
		$criteria = new AssetQuery('craft\elements\Asset');
		$criteria->id = $group;
		$elements = $criteria->all();

		$results = [];
		foreach ($elements as $element) {
			$results[] = [
				'id' => $element->id,
				'icon' => '@vendor/craftcms/cms/src/icons/photo.svg',
				'title' => $element->title,
				'url' => $element->volume->hasUrls ? $element->getUrl() : UrlHelper::cpUrl('settings/assets/volumes/' . $element->volume->id),
			];
		}
		return $results;
	}

	/**
	 * Converts users into a list of standardized result items.
	 * @param group The IDs of the users to convert.
	 */
	private function processUserGroup($group) {
		$criteria = new UserQuery('craft\elements\User');
		$criteria->id = $group;
		$elements = $criteria->all();

		$results = [];
		foreach ($elements as $element) {
			$results[] = [
				'id' => $element->id,
				'icon' => '@vendor/craftcms/cms/src/icons/user.svg',
				'title' => $element->name,
				'url' => $element->cpEditUrl,
			];
		}
		return $results;
	}

	/**
	 * Converts products into a list of standardized result items.
	 * @param group The IDs of the products to convert.
	 */
	private function processProductGroup($group, $siteId) {
		if (!class_exists(ProductQuery::class)) { // Commerce not installed.
			return [];
		}
		$criteria = new ProductQuery('craft\commerce\elements\Product');
		$criteria->id = $group;
		$criteria->siteId = $siteId;
		$elements = $criteria->all();

		$results = [];
		foreach ($elements as $element) {
			$results[] = [
				'id' => $element->id,
				'icon' => '@vendor/craftcms/commerce/src/icon-mask.svg',
				'title' => $element->title,
				'url' => $element->cpEditUrl,
			];
		}
		return $results;
	}

	/**
	 * Converts variants into a list of standardized result items.
	 * @param group The IDs of the variants to convert.
	 */
	private function processVariantGroup($group, $siteId) {
		if (!class_exists(VariantQuery::class)) { // Commerce not installed.
			return [];
		}
		$criteria = new VariantQuery('craft\commerce\elements\Variant');
		$criteria->id = $group;
		$criteria->siteId = $siteId;
		$elements = $criteria->all();

		$results = [];
		foreach ($elements as $element) {
			$results[] = [
				'id' => $element->id,
				'icon' => '@vendor/craftcms/commerce/src/icon-mask.svg',
				'title' => $element->product->title . ': ' . $element->title,
				'url' => $element->cpEditUrl,
			];
		}
		return $results;
	}

	/**
	 * Iterates over elements within each group, converting what it can find into result sets.
	 * @param groups A reference to the groups container that contains the processed and unprocessed elements.
	 * @param siteId The ID of the site to retrieve elements within.
	 */
	private function processRelationshipGroups(&$groups, $siteId) {
		if (count($groups['craft\elements\MatrixBlock'])) {
			$data = $this->processMatrixGroup($groups['craft\elements\MatrixBlock']); // Process the data for this group.
			$groups['craft\elements\MatrixBlock'] = []; // Clear the data for this group.
			$this->integrateGroupData($groups, $data); // Re-integrate new data into the group container.
			$this->processRelationshipGroups($groups, $siteId); // Process more groups.
		} else if (count($groups['craft\commerce\elements\Product'])) {
			$data = $this->processProductGroup($groups['craft\commerce\elements\Product'], $siteId); // Process the data for this group.
			$groups['craft\commerce\elements\Product'] = []; // Clear the data for this group.
			$groups['results'] = array_merge($groups['results'], $data); // Add the results to the set.
			$this->processRelationshipGroups($groups, $siteId); // Process more groups.
		} else if (count($groups['craft\commerce\elements\Variant'])) {
			$data = $this->processVariantGroup($groups['craft\commerce\elements\Variant'], $siteId); // Process the data for this group.
			$groups['craft\commerce\elements\Variant'] = []; // Clear the data for this group.
			$groups['results'] = array_merge($groups['results'], $data); // Add the results to the set.
			$this->processRelationshipGroups($groups, $siteId); // Process more groups.
		} else if (count($groups['craft\elements\Entry'])) {
			$data = $this->processEntryGroup($groups['craft\elements\Entry'], $siteId); // Process the data for this group.
			$groups['craft\elements\Entry'] = []; // Clear the data for this group.
			$groups['results'] = array_merge($groups['results'], $data); // Add the results to the set.
			$this->processRelationshipGroups($groups, $siteId); // Process more groups.
		} else if (count($groups['craft\elements\GlobalSet'])) {
			$data = $this->processGlobalSetGroup($groups['craft\elements\GlobalSet']); // Process the data for this group.
			$groups['craft\elements\GlobalSet'] = []; // Clear the data for this group.
			$groups['results'] = array_merge($groups['results'], $data); // Add the results to the set.
			$this->processRelationshipGroups($groups, $siteId); // Process more groups.
		} else if (count($groups['craft\elements\Category'])) {
			$data = $this->processCategoryGroup($groups['craft\elements\Category'], $siteId); // Process the data for this group.
			$groups['craft\elements\Category'] = []; // Clear the data for this group.
			$groups['results'] = array_merge($groups['results'], $data); // Add the results to the set.
			$this->processRelationshipGroups($groups, $siteId); // Process more groups.
		} else if (count($groups['craft\elements\Tag'])) {
			$data = $this->processTagGroup($groups['craft\elements\Tag']); // Process the data for this group.
			$groups['craft\elements\Tag'] = []; // Clear the data for this group.
			$groups['results'] = array_merge($groups['results'], $data); // Add the results to the set.
			$this->processRelationshipGroups($groups, $siteId); // Process more groups.
		} else if (count($groups['craft\elements\Asset'])) {
			$data = $this->processAssetGroup($groups['craft\elements\Asset']); // Process the data for this group.
			$groups['craft\elements\Asset'] = []; // Clear the data for this group.
			$groups['results'] = array_merge($groups['results'], $data); // Add the results to the set.
			$this->processRelationshipGroups($groups, $siteId); // Process more groups.
		} else if (count($groups['craft\elements\User'])) {
			$data = $this->processUserGroup($groups['craft\elements\User']); // Process the data for this group.
			$groups['craft\elements\User'] = []; // Clear the data for this group.
			$groups['results'] = array_merge($groups['results'], $data); // Add the results to the set.
			$this->processRelationshipGroups($groups, $siteId); // Process more groups.
		}
	}
}