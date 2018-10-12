<?php

namespace Drupal\Tests\graphql\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\graphql\GraphQL\ResolverBuilder;
use Drupal\graphql\GraphQL\ResolverRegistry;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\graphql\Plugin\GraphQL\Schema\SdlSchemaPluginBase;
use Drupal\graphql\Plugin\GraphQL\DataProducer\Entity\EntityLoad;
use Drupal\graphql\Plugin\GraphQL\DataProducer\Entity\EntityId;
use Drupal\graphql\Plugin\GraphQL\DataProducer\String\Uppercase;
use Drupal\graphql\Entity\Server;
use Drupal\Tests\graphql\Traits\QueryResultAssertionTrait;
use Drupal\graphql\GraphQL\Execution\ResolveContext;
use GraphQL\Type\Definition\ResolveInfo;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\Core\TypedData\ComplexDataInterface;
use Drupal\Core\TypedData\TypedDataInterface;

/**
 * @coversDefaultClass \Drupal\graphql\GraphQL\ResolverBuilder
 *
 * @requires module typed_data
 *
 * @group graphql
 */
class ResolverBuilderTest extends GraphQLTestBase {

  use QueryResultAssertionTrait;

  /**
   * {@inheritdoc}
   */
  public function setUp() {
    parent::setUp();

    $gql_schema = <<<GQL
      type Query {
        me: String
        tree(id: Int): Tree
      }

      type Tree {
        id(someArg: Int): Int
        name: String
        uri: String
      }
GQL;
    $this->mockSchema('graphql_test', $gql_schema);
    $this->mockSchemaPluginManager('graphql_test');

    $this->schemaPluginManager->method('createInstance')
      ->with($this->equalTo('graphql_test'))
      ->will($this->returnValue($this->schema));

    $this->container->set('plugin.manager.graphql.schema', $this->schemaPluginManager);

    Server::create([
      'schema' => 'graphql_test',
      'name' => 'graphql_test',
      'endpoint' => '/graphql_test'
    ])->save();
  }

  /**
   * {@inheritdoc}
   */
  protected function defaultCacheTags() {
    return ['graphql_response'];
  }

  /**
   * Return the default schema for this test.
   *
   * @return string
   *   The default schema id.
   */
  protected function getDefaultSchema() {
    return 'graphql_test';
  }

  /**
   * @covers ::produce
   *
   * @dataProvider testBuilderProducingProvider
   *
   * @param $input
   * @param $expected
   */
  public function testBuilderProducing($input, $expected) {
    $builder = new ResolverBuilder();
    $plugin = $builder->produce($input, []);
    $this->assertInstanceOf($expected, $plugin);
  }

  public function testBuilderProducingProvider() {
    return [
      ['entity_load', EntityLoad::class],
      ['entity_id', EntityId::class],
      ['uppercase', Uppercase::class],
    ];
  }

  /**
   * @covers ::fromValue
   */
  public function testFromValue() {
    $builder = new ResolverBuilder();

    $registry = new ResolverRegistry([]);
    $registry->addFieldResolver('Query', 'me', $builder->fromValue('some me'));
    $this->schema->expects($this->any())
      ->method('getResolverRegistry')
      ->willReturn($registry);

    $query = <<<GQL
      query {
        me
      }
GQL;

    $this->assertResults($query, [], ['me' => 'some me'], $this->defaultCacheMetaData());
  }

  /**
   * @covers ::fromParent
   */
  public function testFromParent() {
    $builder = new ResolverBuilder();

    $registry = new ResolverRegistry([]);

    $registry->addFieldResolver('Query', 'tree', $builder->fromValue('Some string value'));

    $registry->addFieldResolver('Tree', 'name', $builder->fromParent());

    $this->schema->expects($this->any())
      ->method('getResolverRegistry')
      ->willReturn($registry);

    $query = <<<GQL
      query {
        tree {
          name
        }
      }
GQL;

    $this->assertResults($query, [], ['tree' => ['name' => 'Some string value']], $this->defaultCacheMetaData());
  }

  /**
   * @covers ::fromArgument
   */
  public function testFromArgument() {
    $builder = new ResolverBuilder();

    $registry = new ResolverRegistry([]);

    $registry->addFieldResolver('Query', 'tree', $builder->fromValue(['name' => 'some tree', 'id' => 5]));

    $registry->addFieldResolver('Tree', 'id', $builder->fromArgument('someArg'));

    $this->schema->expects($this->any())
      ->method('getResolverRegistry')
      ->willReturn($registry);

    $query = <<<GQL
      query {
        tree(id: 5) {
          id(someArg: 234)
        }
      }
GQL;

    $this->assertResults($query, [], ['tree' => ['id' => 234]], $this->defaultCacheMetaData());
  }

  /**
   * @covers ::fromPath
   */
  public function testFromPath() {
    $builder = new ResolverBuilder();
    $registry = new ResolverRegistry([]);

    $typed_data_manager = $this->getMock(TypedDataManagerInterface::class);
    $typed_data_manager->expects($this->any())
      ->method('createDataDefinition')
      ->willReturn($this->getMock('Drupal\Core\TypedData\DataDefinitionInterface'));

    $typed_data_manager->expects($this->any())
      ->method('getDefinition')
      ->will($this->returnValueMap([
        'tree' => ['class' => '\Drupal\Core\TypedData\ComplexDataInterface'],
      ]));

    $uri = $this->prophesize(TypedDataInterface::class);
    $uri->getValue()
      ->willReturn('<front>');

    $path = $this->prophesize(ComplexDataInterface::class);
    $path->get('uri')
      ->willReturn($uri);
    $path->getValue()
      ->willReturn([]);

    $tree_type = $this->prophesize(ComplexDataInterface::class);
    $tree_type->get('path')
      ->willReturn($path);
    $tree_type->getValue()
      ->willReturn([]);

    $typed_data_manager->expects($this->any())
      ->method('create')
      ->willReturn($tree_type->reveal());

    $this->container->set('typed_data_manager', $typed_data_manager);

    $registry->addFieldResolver('Query', 'tree', $builder->fromValue([
      'path' => [
        'uri' => '<front>',
        'path_name' => 'Front page',
      ]
    ]));

    $registry->addFieldResolver('Tree', 'uri', $builder->fromPath('tree', 'path.uri'));

    $this->schema->expects($this->any())
      ->method('getResolverRegistry')
      ->willReturn($registry);

    $query = <<<GQL
      query {
        tree {
          uri
        }
      }
GQL;

    $this->assertResults($query, [], ['tree' => ['uri' => '<front>']], $this->defaultCacheMetaData());
  }


}
