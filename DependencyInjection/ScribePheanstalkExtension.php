<?php

namespace Scribe\PheanstalkBundle\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Symfony\Component\DependencyInjection\Loader;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

use Scribe\PheanstalkBundle\Exceptions\PheanstalkException;

/**
 * This is the class that loads and manages your bundle configuration
 *
 * To learn more see {@link http://symfony.com/doc/current/cookbook/bundles/extension.html}
 */
class ScribePheanstalkExtension extends Extension
{
    protected function reservedName()
    {
        return array(
                'pheanstalks',
                'pheanstalk_locator',
                'proxy',
                'data_collector',
                'listener',
                'event',
            );
    }

    /**
     * {@inheritDoc}
     */
    public function load(array $configs, ContainerBuilder $container)
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $loader = new Loader\XmlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('services.xml');

        if (!$config['enabled']) {
            return;
        }

        $this->configureConnections($container, $config);

        if ($config['profiler']['enabled']) {
            $this->configureProfiler($container, $config);
        }
    }

    /**
     * Configures the Connections and Connection Locator
     *
     * @param  \Symfony\Component\DependencyInjection\ContainerBuilder $container
     * @param  array                                                   $config
     * @throws \Scribe\PheanstalkBundle\Exceptions\PheanstalkException
     */
    public function configureConnections(ContainerBuilder $container, array $config)
    {
        // Create a connection locator that will reference all existing connection
        $connectionLocatorDef = new Definition("Scribe\PheanstalkBundle\PheanstalkLocator");
        $container->setDefinition("scribe.pheanstalk.pheanstalk_locator", $connectionLocatorDef);

        $defaultPheanstalkName = null;
        $pheanstalks = $config['pheanstalks'];

        $pheanstalkLocatorDef = $container->getDefinition("scribe.pheanstalk.pheanstalk_locator");

        // For each connection in the configuration file
        foreach ($pheanstalks as $name => $pheanstalk) {
            if (in_array($name, $this->reservedName())) {
                throw new \RuntimeException('Reserved pheanstalk name : ' . $name);
            }

            $pheanstalkConfig = array($pheanstalk['server'], $pheanstalk['port'], $pheanstalk['timeout']);
            $isDefault = $pheanstalk['default'];

            $pheanstalkDef = $container->getDefinition($pheanstalk['proxy']);

            //TODO: Add Reflection to check PheanstalkProxyInterface implementation
            //$pheanstalkRefl = new \ReflectionClass($pheanstalkDef->getClass());
            //$pheanstalkRefl->implementsInterface('Scribe\PheanstalkBundle\Proxy\PheanstalkProxyInterface')
            $pheanstalkDef->addMethodCall('setPheanstalk', array(new Definition('Pheanstalk_Pheanstalk', $pheanstalkConfig)));
            $pheanstalkDef->addMethodCall('setName', array($name));

            $container->setDefinition("scribe.pheanstalk." . $name, $pheanstalkDef);

            // Register the connection in the connection locator
            $pheanstalkLocatorDef->addMethodCall('addPheanstalk', array(
                $name,
                $container->getDefinition("scribe.pheanstalk." . $name),
                $isDefault
            ));

            if ($isDefault) {
                if (null !== $defaultPheanstalkName) {
                    throw new PheanstalkException(sprintf("Default pheanstalk already defined. '%s' & '%s'", $defaultPheanstalkName, $name));
                }

                $defaultPheanstalkName = $name;
                $container->setAlias("scribe.pheanstalk", "scribe.pheanstalk." . $name);
            }
        }
    }

    /**
     * Configures the profiler data collector
     *
     * @param ContainerBuilder $container Container
     * @param array            $config    Configuration
     */
    public function configureProfiler(ContainerBuilder $container, array $config)
    {
        // Setup the data collector service for Symfony profiler
        $dataCollectorDef = new Definition('Scribe\PheanstalkBundle\DataCollector\PheanstalkDataCollector');
        $dataCollectorDef->setPublic(false);
        $dataCollectorDef->addTag('data_collector', array('id' => 'pheanstalk', 'template' => $config['profiler']['template']));
        $dataCollectorDef->addArgument(new Reference('scribe.pheanstalk.pheanstalk_locator'));

        $container->setDefinition("scribe.pheanstalk.data_collector", $dataCollectorDef);
    }

    public function process(ContainerBuilder $container)
    {
        if (!$container->hasParameter('scribe.pheanstalk.pheanstalks')) {
            return;
        }

    }
}
