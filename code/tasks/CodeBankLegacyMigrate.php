<?php
class CodeBankLegacyMigrate extends BuildTask {
    /**
     * @var {string}
     */
    protected $title='Legacy Code Bank Migration';
    
    /**
     * @var {string}
     */
    protected $description='Migrates a Code Bank 2.2 Database to the new system, please run this only once when migrating from a 2.2 install of Code Bank';
    
    /**
     * Performs the migration
     */
    public function run($request) {
        //Check for tables
        $tables=DB::tableList();
        if(!array_key_exists('languages', $tables) || !array_key_exists('snippits', $tables) || !array_key_exists('snippit_history', $tables) || !array_key_exists('preferences', $tables) || !array_key_exists('settings', $tables) || !array_key_exists('snippit_search', $tables) || !array_key_exists('users', $tables)) {
            echo '<b>Could not find Code Bank 2.2 database tables, cannot migrate</b>';
            exit;
        }
        
        
        //Ensure Empty
        if(Snippet::get()->Count()>0) {
            echo '<b>Already appears to be snippets present in the database, please start with a clean database, cannot migrate.</b>';
            exit;
        }
        
        
        //Increase Timeout, since 30s probably won't be enough in huge databases
        increase_time_limit_to(600);
        
        
        //Find Other language
        $plainTextID=SnippetLanguage::get()->filter('Name', 'Other')->first();
        if(empty($plainTextID) || $plainTextID==false || $plainTextID->ID==0) {
            echo 'Could not find the Other Language, cannot migrate, please run dev/build first';
            exit;
        }else {
            $plainTextID=$plainTextID->ID;
        }
        
        
        //Check for users group
        $usersGroup=Group::get()->filter('Code', 'code-bank-api')->first();
        if(empty($usersGroup) || $usersGroup==false || $usersGroup->ID==0) {
            echo 'Could not find users group, cannot migrate, please run dev/build first';
            exit;
        }
        
        
        //Migrate Languages
        echo '<b>Migrating User Languages</b>... ';
        $results=DB::query('SELECT * FROM "languages" WHERE "user_language"=1');
        if($results->numRecords()>0) {
            foreach($results as $row) {
                DB::query('INSERT INTO "SnippetLanguage" ("ClassName","Created", "LastEdited", "Name", "FileExtension", "HighlightCode", "UserLanguage") '.
                        "VALUES('SnippetLanguage','".date('Y-m-d H:i:s')."','".date('Y-m-d H:i:s')."','".Convert::raw2sql($row['language'])."','".Convert::raw2sql($row['file_extension'])."','".Convert::raw2sql($row['sjhs_code'])."',1)");
            }
            
            echo 'Done<br/>';
        }else {
            echo 'None Found<br/>';
        }
        
        
        //Migrate Users
        echo '<b>Migrating Users</b>...';
        
        $results=DB::query('SELECT * FROM "users"');
        if($results->numRecords()>0) {
            foreach($results as $row) {
                //Get user heartbeat preference
                $useHeartbeat=DB::query('SELECT "value" FROM "preferences" WHERE "code"=\'heartbeat\' AND "fkUser"='.$row['id'])->value();
                
                
                //Insert User
                $member=Member::get()->filter('Email', $row['username'])->first();
                if(empty($member) || $member===false || $member->ID==0) {
                    $member=new Member();
                    $member->FirstName=$row['username'];
                    $member->Email=$row['username'];
                    $member->Password=$row['password'];
                    $member->PasswordEncryption='sha1';
                    $member->Locale='en_US';
                    $member->DateFormat='MMM d, yyyy';
                    $member->TimeFormat='h:mm:ss a';
                    $member->LockedOutUntil=($row['deleted']==true ? '2037-12-31 11:59:59':null);
                    $member->UseHeartbeat=intval($useHeartbeat);
                    $member->write();
                    
                    
                    //Add to security group
                    if($row['username']=='admin') {
                        //For admin add to administrators group
                        $member->addToGroupByCode('administrators');
                    }else {
                        //For all others add to code-bank-api
                        $member->addToGroupByCode('code-bank-api');
                    }
                }else {
                    //Add to code-bank-api if not admin
                    if($row['username']!='admin') {
                        $member->addToGroupByCode('code-bank-api');
                    }
                    
                    echo '<br/><i>WARNING: Member '.$row['username'].' already exists in the database, no changes have been made to this member. If you are unsure of the password please ask an administrator to have it reset or use the forgot password link</i><br/>';
                }
            }
            
            echo 'Done<br/>';
        }else {
            echo 'No users found, Code Bank 2.2 appears to have not been properly setup cannot continue with migration';
            exit;
        }
        
        
        //Migrate IP Message
        echo '<b>Migrating IP Message</b>...';
        $ipMessage=DB::query('SELECT "value" FROM "settings" WHERE "code"=\'ipMessage\'')->value();
        $config=CodeBankConfig::CurrentConfig();
        $config->IPMessage=$ipMessage;
        $config->write();
        echo 'Done<br/>';
        
        
        //Migrate Snippets
        echo '<b>Migrating Snippets</b>...';
        $results=DB::query('SELECT "snippits".*, "languages"."language", "creator"."username" AS "creatorUsername", "lastEditor"."username" AS "lastEditorUsername" '.
                            'FROM "snippits" '.
                                'INNER JOIN "languages" ON "snippits"."fkLanguage"="languages"."id" '.
                                'LEFT JOIN "users" "creator" ON "snippits"."fkCreatorUser"="creator"."id" '.
                                'LEFT JOIN "users" "lastEditor" ON "snippits"."fkLastEditUser"="lastEditor"."id"');
        if($results->numRecords()>0) {
            foreach($results as $row) {
                //Get Creator ID
                $creator=Member::get()->filter('Email', $row['creatorUsername'])->first();
                if(!empty($creator) && $creator!==false && $creator->ID!=0) {
                    $creatorID=$creator->ID;
                }else {
                    $creatorID=0;
                }
                
                
                //Get Last Editor ID
                $lastEditor=Member::get()->filter('Email', $row['lastEditorUsername'])->first();
                if(!empty($lastEditor) && $lastEditor!==false && $lastEditor->ID!=0) {
                    $lastEditorID=$lastEditor->ID;
                }else {
                    $lastEditorID=0;
                }
                
                
                //Get Language ID
                $language=SnippetLanguage::get()->filter('Name', $row['language'])->first();
                if(!empty($language) && $language!==false && $language->ID!=0) {
                    $languageID=$language->ID;
                }else {
                    $languageID=$plainTextID;
                }
                
                
                //Insert Snippet Info
                DB::query('INSERT INTO "Snippet" ("ID", "ClassName", "Created", "LastEdited", "Title", "Description", "Tags", "LanguageID", "CreatorID", "LastEditorID") '.
                        "VALUES(".$row['id'].",'Snippet','".date('Y-m-d H:i:s')."','".date('Y-m-d H:i:s')."','".Convert::raw2sql($row['title'])."','".Convert::raw2sql($row['description'])."','".Convert::raw2sql($row['tags'])."',".$languageID.",".$creatorID.",".$lastEditorID.")");
                
                
                //Get History
                $versions=DB::query('SELECT * FROM "snippit_history" WHERE "fkSnippit"='.$row['id']);
                foreach($versions as $version) {
                    DB::query('INSERT INTO "SnippetVersion" ("ClassName", "Created", "LastEdited", "Text", "ParentID") '.
                            "VALUES('SnippetVersion','".date('Y-m-d H:i:s', strtotime($version['date']))."','".date('Y-m-d H:i:s', strtotime($version['date']))."','".Convert::raw2sql($version['text'])."',".$row['id'].")");
                                
                }
            }
            
            
            echo 'Done<br/>';
        }else {
            echo 'No snippets found<br/>';
        }
        
        
        echo '<br/><h4>Migration Completed</h4>';
        exit;
    }
}
?>