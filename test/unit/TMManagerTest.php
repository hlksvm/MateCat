<?php 

use Analysis\TMManager, Analysis\Commons\RedisKeys, Analysis\Queue\QueuesList;

class TMManagerTest extends AbstractTest {

    protected $_TMInstance;

    /**
     * @var \RedisHandler
     */
    protected $_redisClient;

    public function setUp() {

        \INIT::$DEBUG = false;
        $this->_TMInstance = TMManager::getInstance();

        $this->_redisClient = new \RedisHandler();
        $this->_redisClient->getConnection()->set( RedisKeys::VOLUME_ANALYSIS_PID, 1224 );
        for($i=0;$i<30;$i++){
            $this->_redisClient->getConnection()->sadd( RedisKeys::VA_CHILD_PID_SET_DEFAULT, mt_rand(15, 20000) );
            $this->_redisClient->getConnection()->sadd( RedisKeys::VA_CHILD_PID_SET_P2, mt_rand(20001, 40000) );
            $this->_redisClient->getConnection()->sadd( RedisKeys::VA_CHILD_PID_SET_P3, mt_rand(40001, 65535) );
        }

    }

    public function tearDown() {
        $this->_redisClient->getConnection()->del( RedisKeys::VOLUME_ANALYSIS_PID );
        $this->_redisClient->getConnection()->del( RedisKeys::VA_CHILD_PID_SET_DEFAULT );
        $this->_redisClient->getConnection()->del( RedisKeys::VA_CHILD_PID_SET_P2 );
        $this->_redisClient->getConnection()->del( RedisKeys::VA_CHILD_PID_SET_P3 );
    }

    public function testDeletePid(){

        $reflectedMethod = new ReflectionMethod( $this->_TMInstance, '_killPids' );
        $reflectedMethod->setAccessible( true );

        //TEST remove pid 2 ( seek and destroy )
        //put a known pid into the list
        $this->_redisClient->getConnection()->sadd( RedisKeys::VA_CHILD_PID_SET_P2, 2 );
        //check for correctness
        $this->assertTrue( $this->_redisClient->getConnection()->sismember( RedisKeys::VA_CHILD_PID_SET_P2, 2 ) );
        $reflectedMethod->invokeArgs( $this->_TMInstance, array( null, 2 ) );
        $list = $this->_redisClient->getConnection()->smembers( RedisKeys::VA_CHILD_PID_SET_P2 );
        $this->assertNotContains( 2, $list );


        //remove 3 elements from queue 1
        $x = QueuesList::get();
        $old_len = $this->_redisClient->getConnection()->scard( $x->list[0]->pid_set_name );
        $reflectedMethod->invokeArgs( $this->_TMInstance, array( $x->list[0], 0, 3 ) );
        $new_len = $this->_redisClient->getConnection()->scard( $x->list[0]->pid_set_name );
        $this->assertEquals( 3, $old_len - $new_len );
        //put a known pid into the list
        $this->_redisClient->getConnection()->sadd( RedisKeys::VA_CHILD_PID_SET_P3, 99999 );
        //check for correctness
        $this->assertTrue( $this->_redisClient->getConnection()->sismember( RedisKeys::VA_CHILD_PID_SET_P3, 99999 ) );
        $reflectedMethod->invokeArgs( $this->_TMInstance, array( $x->list[2], 99999 ) );
        $list = $this->_redisClient->getConnection()->smembers( RedisKeys::VA_CHILD_PID_SET_P2 );
        $this->assertNotContains( 99999, $list );


        //remove 5 pids by balancing
        $old_len = $this->_redisClient->getConnection()->scard( $x->list[0]->pid_set_name );
        $old_len += $this->_redisClient->getConnection()->scard( $x->list[1]->pid_set_name );
        $old_len += $this->_redisClient->getConnection()->scard( $x->list[2]->pid_set_name );
        $reflectedMethod->invokeArgs( $this->_TMInstance, array( null, 0, 5 ) );
        $new_len = $this->_redisClient->getConnection()->scard( $x->list[0]->pid_set_name );
        $new_len += $this->_redisClient->getConnection()->scard( $x->list[1]->pid_set_name );
        $new_len += $this->_redisClient->getConnection()->scard( $x->list[2]->pid_set_name );
        $this->assertEquals( 5, $old_len - $new_len );


        //delete all pid in a list
        $reflectedMethod->invokeArgs( $this->_TMInstance, array( $x->list[2] ) );
        $new_len = $this->_redisClient->getConnection()->scard( $x->list[2]->pid_set_name );
        $this->assertEquals( 0, $new_len );

        //bad and stranges

        //remove a non existent pid from a list
        //there is not. expected: do nothing
        $old_len = $this->_redisClient->getConnection()->scard( $x->list[1]->pid_set_name );
        $reflectedMethod->invokeArgs( $this->_TMInstance, array( $x->list[1], 99999 ) );
        $new_len = $this->_redisClient->getConnection()->scard( $x->list[1]->pid_set_name );
        $this->assertEquals( 0, $old_len - $new_len );
        $this->assertNotEquals( 0, $new_len );


        //Bad invocation, expected: pid kill routine but pid not found ( do nothing, ignore third param )
        $old_list = $this->_redisClient->getConnection()->smembers( RedisKeys::VA_CHILD_PID_SET_DEFAULT );
        $old_list += $this->_redisClient->getConnection()->smembers( RedisKeys::VA_CHILD_PID_SET_P2 );
        $old_list += $this->_redisClient->getConnection()->smembers( RedisKeys::VA_CHILD_PID_SET_P3 );
        $this->assertNotContains( 99999, $old_list );
        $reflectedMethod->invokeArgs( $this->_TMInstance, array( null, 99999, 3 ) );
        $new_list = $this->_redisClient->getConnection()->smembers( RedisKeys::VA_CHILD_PID_SET_DEFAULT );
        $new_list += $this->_redisClient->getConnection()->smembers( RedisKeys::VA_CHILD_PID_SET_P2 );
        $new_list += $this->_redisClient->getConnection()->smembers( RedisKeys::VA_CHILD_PID_SET_P3 );
        $this->assertNotContains( 99999, $new_list );
        $this->assertEquals( $new_list, $old_list );


        //expected kill pid 99999 from queue2 and not found ( do nothing, ignore third param )
        $old_list = $this->_redisClient->getConnection()->smembers( RedisKeys::VA_CHILD_PID_SET_DEFAULT );
        $this->assertNotContains( 99999, $old_list );
        $reflectedMethod->invokeArgs( $this->_TMInstance, array( $x->list[0], 99999, 3 ) );
        $new_list = $this->_redisClient->getConnection()->smembers( RedisKeys::VA_CHILD_PID_SET_DEFAULT );
        $this->assertNotContains( 99999, $new_list );


        //expected kill ALL pids and exit from infinite loop
        $old_list = $this->_redisClient->getConnection()->smembers( RedisKeys::VA_CHILD_PID_SET_DEFAULT );
        $old_list += $this->_redisClient->getConnection()->smembers( RedisKeys::VA_CHILD_PID_SET_P2 );
        $old_list += $this->_redisClient->getConnection()->smembers( RedisKeys::VA_CHILD_PID_SET_P3 );
        $this->assertNotEmpty( $old_list );
        $this->assertLessThan( 3000, count( $old_list ) );
        $reflectedMethod->invokeArgs( $this->_TMInstance, array( null, 0, 3000 ) );
        $new_list = $this->_redisClient->getConnection()->smembers( RedisKeys::VA_CHILD_PID_SET_DEFAULT );
        $new_list += $this->_redisClient->getConnection()->smembers( RedisKeys::VA_CHILD_PID_SET_P2 );
        $new_list += $this->_redisClient->getConnection()->smembers( RedisKeys::VA_CHILD_PID_SET_P3 );
        $this->assertEmpty( $new_list  );

        //empty all queues
        $this->setUp();
        //remove second set
        $this->_redisClient->getConnection()->del( RedisKeys::VA_CHILD_PID_SET_P2 );

        $old_list = $this->_redisClient->getConnection()->smembers( RedisKeys::VA_CHILD_PID_SET_DEFAULT );
        $old_list += $this->_redisClient->getConnection()->smembers( RedisKeys::VA_CHILD_PID_SET_P3 );
        $this->assertNotEmpty( $old_list );
        $reflectedMethod->invokeArgs( $this->_TMInstance, array() );
        $new_list = $this->_redisClient->getConnection()->smembers( RedisKeys::VA_CHILD_PID_SET_DEFAULT );
        $new_list += $this->_redisClient->getConnection()->smembers( RedisKeys::VA_CHILD_PID_SET_P3 );
        $this->assertEmpty( $new_list );

    }

}