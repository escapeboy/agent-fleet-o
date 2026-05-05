<?php

namespace Tests\Unit\Domain\KnowledgeGraph;

use App\Domain\KnowledgeGraph\Services\LouvainCommunityDetector;
use PHPUnit\Framework\TestCase;

class LouvainCommunityDetectorTest extends TestCase
{
    private LouvainCommunityDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = new LouvainCommunityDetector;
    }

    public function test_two_clear_clusters_get_different_community_ids(): void
    {
        // Cluster A: A1-A2-A3 (triangle)
        // Cluster B: B1-B2-B3 (triangle)
        // One weak bridge: A1-B1
        $nodes = ['A1', 'A2', 'A3', 'B1', 'B2', 'B3'];
        $edges = [
            ['A1', 'A2'], ['A2', 'A3'], ['A3', 'A1'],
            ['B1', 'B2'], ['B2', 'B3'], ['B3', 'B1'],
            ['A1', 'B1'],
        ];

        $result = $this->detector->detect($nodes, $edges);

        $this->assertCount(6, $result);

        // All A nodes should be in the same community
        $commA1 = $result['A1'];
        $this->assertEquals($commA1, $result['A2']);
        $this->assertEquals($commA1, $result['A3']);

        // All B nodes should be in the same community
        $commB1 = $result['B1'];
        $this->assertEquals($commB1, $result['B2']);
        $this->assertEquals($commB1, $result['B3']);

        // The two clusters should be different communities
        $this->assertNotEquals($commA1, $commB1);
    }

    public function test_isolated_nodes_form_their_own_communities(): void
    {
        $nodes = ['X', 'Y', 'Z'];
        $edges = []; // no edges

        $result = $this->detector->detect($nodes, $edges);

        $this->assertCount(3, $result);
        // All different communities
        $this->assertNotEquals($result['X'], $result['Y']);
        $this->assertNotEquals($result['Y'], $result['Z']);
        $this->assertNotEquals($result['X'], $result['Z']);
    }

    public function test_empty_input_returns_empty_array(): void
    {
        $result = $this->detector->detect([], []);
        $this->assertSame([], $result);
    }

    public function test_single_node_returns_community_zero(): void
    {
        $result = $this->detector->detect(['node1'], []);
        $this->assertSame(['node1' => 0], $result);
    }

    public function test_same_input_produces_same_output(): void
    {
        $nodes = ['N1', 'N2', 'N3', 'N4', 'N5'];
        $edges = [
            ['N1', 'N2'], ['N2', 'N3'],
            ['N3', 'N4'], ['N4', 'N5'],
            ['N1', 'N3'],
        ];

        $result1 = $this->detector->detect($nodes, $edges);
        $result2 = $this->detector->detect($nodes, $edges);

        $this->assertSame($result1, $result2);
    }

    public function test_all_results_are_valid_community_ids(): void
    {
        $nodes = ['A', 'B', 'C', 'D'];
        $edges = [['A', 'B'], ['C', 'D']];

        $result = $this->detector->detect($nodes, $edges);

        foreach ($result as $nodeId => $communityId) {
            $this->assertIsInt($communityId);
            $this->assertGreaterThanOrEqual(0, $communityId);
            $this->assertContains($nodeId, $nodes);
        }
    }
}
