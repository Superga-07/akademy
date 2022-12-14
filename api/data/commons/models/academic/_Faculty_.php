<?php


abstract class _Faculty_ extends Data
{
    protected $nom, $doyen;
    protected $id = 0;
    protected $academic = 0;
    protected $stats = [];
    protected $grades = [];
    public static $list = [];
    protected static string $nomenclature = "Faculty";

    public function getNom() {
        return $this->nom;
    }

    public function getGrades(){
        return $this->grades;
    }

    public function setNom(string $nom) {
        $this->nom = $nom;
        return $this;
    }

    public function getId() {
        return $this->id;
    }

    protected function fetchStat(){
        $db = Storage::Connect();
        $chk = $db->prepare("select id, academie from annee_academique order by academie");
        $ya = []; 
        try{
            $chk->execute();
            while($ya_data = $chk->fetch()) {
                $ya[$ya_data["id"]] = $ya_data["academie"];
            }
            foreach($ya as $k => $v){

                $data = [];

                //Ensemble d'étudiants enregistrés
                $chk = $db->prepare("select count(e.id) as stud from etudiants e, niveau n, filieres f where e.niveau = n.id and n.filiere = :p1 and f.id = :p1 and f.annee_academique in 
                    (select DISTINCT y.id from annee_academique a, annee_academique y where a.annee_debut <= YEAR(NOW()) and a.id = :p2 and a.annee_debut >= y.annee_debut)");

                try{
                    $chk->execute([
                        "p1"=> $this->id,
                        "p2"=> $k
                    ]);
                    $data["student_total"] = (int) $chk->fetch()["stud"];
                }catch(Exception $e){\System\Log::printStackTrace($e);}

                //Ensemble d'étudiant actif
                $chk = $db->prepare("
                                    select count(e.id) as stud 
                                    from etudiants e, niveau n, filieres f 
                                    where 
                                          e.niveau = n.id and n.filiere = :p1 and 
                                          f.id = :p1 and e.etat = 'A' and 
                                          f.annee_academique in (
                                              select DISTINCT y.id from annee_academique a, annee_academique y 
                                              where 
                                                    a.id = :p2 and a.annee_debut >= y.annee_debut
                                          )
                  ");

                try{
                    $chk->execute([
                        "p1"=> $this->id,
                        "p2"=> $k
                    ]);
                    $data["active_student_total"] = (int) $chk->fetch()["stud"];
                }catch(Exception $e){\System\Log::printStackTrace($e);}

                //Ensemble de cours enregistrés
                $chk = $db->prepare("select count(*) as course from 
                                    (select DISTINCT c.id from cours c, dispensation h, niveau n where h.cours = c.id and c.niveau = n.id and n.filiere = :p1 and c.annee_academique = :p2) as fl_cours");
                try{
                    $chk->execute([
                        "p1"=> $this->id,
                        "p2"=> $k
                    ]);
                    $data["course_total"] = (int) $chk->fetch()["course"];
                }catch (Exception $e){\System\Log::printStackTrace($e);}

                //Ensemble de cours dispensés
                $chk = $db->prepare("select count(*) as course from 
                                        (select DISTINCT c.id from cours c, dispensation h, niveau n where h.cours = c.id and c.etat in ('D','E') and c.niveau = n.id and n.filiere = :p1 and c.annee_academique = :p2) as fl_cours");
                try{
                    $chk->execute(["p1"=> $this->id, "p2"=> $k]);
                    $data["given_course_total"] = (int) $chk->fetch()["course"];
                }catch(Exception $e){\System\Log::printStackTrace($e);}

                //Ensemble de professeur
                $chk = $db->prepare("select count(*) as prof from 
                     (select DISTINCT p.id from professeurs p, cours c, niveau n, dispensation h where 
                     (c.titulaire = p.id or c.suppleant = p.id) and c.niveau = n.id and n.filiere = :p1 and h.cours = c.id and c.annee_academique = :p2) as fl_prof");


                try{
                    $chk->execute([
                        "p1"=> $this->id,
                        "p2"=> $k
                    ]);
                    $data["teacher_total"] = (int) $chk->fetch()["prof"];
                }catch(Exception $e){\System\Log::printStackTrace($e);}

                //Ensemble de promotions
                $chk = $db->prepare("select distinct count(*) as promo from salle_classe s, niveau n where
                                            n.filiere = :p1 and n.id = s.niveau and s.annee_academique=:p2
                                        ");
                try {
                    $chk->execute([
                        'p1' => $this->id,
                        'p2' => $k
                    ]);
                    $data["promotion_total"] = (int) $chk->fetch()["promo"];
                }catch(Exception $e){\System\Log::printStackTrace($e);}

                $this->stats[$v] =  $data;
            }
        }catch(Exception $e){\System\Log::printStackTrace($e);}
        $chk->closeCursor();
    }

    public function save(){
        if(!CheckIf::isFormalName($this->nom) && self::$nomenclature == "Faculty"){
            \System\Log::println("[Noooo] !");
            return _str["faculty.name.invalid"];
        }
        else if(self::$nomenclature != "Faculty" && !CheckIf::isAcademicLevelName($this->nom)){
            \System\Log::println("[Yesss] !");
            return _str["faculty.name.invalid"];
        }
        if(Storage::$currentYear == null){
            return "There's no academic year open to do this operation !";
        }
        if(Faculty::nameExists($this)){
            return self::nomenclature." name already exists !";
        }
        $r = "Execution error !";
        $db = Storage::Connect();
        $chk = null;
        $data = [];
        if($this->id == 0){
            $chk = $db->prepare("insert into filieres(nom,annee_academique,version) value(:p1,:p3,:p4)");
            $data["p3"] = Storage::$currentYear->getId();
        }
        else{
            $chk = $db->prepare("update filieres set nom=:p1, version=:p4 where id=:p2");
            $data["p2"] = $this->id;
        }
        $data["p1"] = $this->nom;
        $data["p4"] = Version::get();
        try{
            $chk->execute($data);
            $r = null;
            Storage::update($this->refresh());
        }catch(Exception $e){\System\Log::printStackTrace($e);}
        $chk->closeCursor();
        return $r;
    }

    public function refresh(){
        $req = Storage::Connect()->prepare("select * from filieres where nom=:p1");
        $req->execute([
            'p1'=>$this->nom
        ]);
        if($req->rowCount()){
            $this->hydrate($req->fetch());
        }
        $req->closeCursor();
        return $this;
    }

    public function delete() {
        $r = false;
        $db = Storage::Connect();
        $chk = $db->prepare("delete from filieres where id=:p1 order by nom asc");

        try{
            $chk->execute(["p1"=> $this->id]);
            $r = true;
            Storage::update($this);
        }catch (Exception $e){\System\Log::printStackTrace($e);}
        $chk->closeCursor();
        return $r;
    }

    public function fetchGrades(){
        $db = Storage::Connect();
        $chk = $db->prepare("select * from niveau where filiere=:p1 order by annee asc");
        try{
            $chk->execute(["p1"=> $this->id]);
            while($result = $chk->fetch()){
                $this->grades[] = (new Grade())->hydrate($result);
            }
        }catch(Exception $e){\System\Log::printStackTrace($e);}
        $chk->closeCursor();
    }

    public function getAcademicYear(){
        return AcademicYear::getById($this->academic);
    }

    public function getAcademicYearId(){
        return $this->academic;
    }

    public function hydrate(array $data){
        $this->id = (int) $data["id"];
        $this->nom = $data["nom"];
        $this->academic = (int) $data["annee_academique"];
        $this->fetchGrades();
        $this->fetchStat();
    }

    public static function getById(int $id){
        $r = null;
        $db = Storage::Connect();
        $chk = $db->prepare("select * from filieres where id=:p1");
        try{
            $chk->execute(["p1"=>$id]);
            if($chk->rowCount()){
                $r = new Faculty();
                $r->hydrate($chk->fetch());
            }
        }catch(Exception $e){\System\Log::printStackTrace($e);}
        $chk->closeCursor();
        return $r;
    }

    public static function nameExists(_Faculty_ $fac){
        $r = false;
        foreach(self::fetchAll() as $f){
            if(strtolower($f->getNom()) == strtolower($fac->getNom()) && $f->getId() != $fac->getId()){
                $r = true;
                break;
            }
        }
        return $r;
    }

    public static function fetchAll($override = false){
        if(!$override && count(self::$list) > 0){
            return self::$list;
        }
        self::$list = [];
        $db = Storage::Connect();
        $chk = $db->prepare("select * from filieres where version > :p98 order by id asc");
        try{
            $chk->execute(["p98"=>Version::getUserQuery()]);
            while($data = $chk->fetch()){
                $fac = new Faculty();
                $fac->hydrate($data);
                self::$list[] = $fac;
            }
        }catch(Exception $e){\System\Log::printStackTrace($e);}
        $chk->closeCursor();
        return self::$list;
    }

    public static function getBySoundLike(string $name = null){
        if($name == null) return null;
        $name = preg_replace("/( +)/", " ",CheckIf::clearMeta(strtolower($name)));
        $r = null;
        $occurence = 0;
        foreach(self::fetchAll() as $f){
            if(preg_match("/(.+?)?".$name."(.+?)?/", strtolower($f->getNom())) ){
                $r = $f;
                $occurence++;
                if($occurence >= 2) break;
            }
        }
        return $occurence > 1 ? null : $r;
    }

    public function getGradeSoundLike(string $name = null){
        if($name == null) return 0;
        $r = 0; $occurence = 0;
        $name = preg_replace("/( +)/", " ",CheckIf::clearMeta(strtolower($name)));
        foreach($this->grades as $k => $v){
            if(preg_match("/(.+?)?".$name."(.+?)?/", strtolower($v["notation"])) ){
                $r = $k;
                $occurence++;
                if($occurence >= 2) break;
            }
        }
        return $occurence > 1 ? 0 : $r;
    }

    public function nextGrade(int $from){
        $r = 0;
        $db = Storage::Connect();
        $chk = $db->prepare("select g.id from niveau n, niveau g where n.annee < g.annee and g.filiere = :p1 and n.id = :p2 and n.filiere= :p1 order by g.id asc limit 1");

        try{
            $chk->execute([
                "p1"=> $this->id,
                "p2"=> $from
            ]);
            if($chk->rowCount()){
                $r = (int) $chk->fetch()["id"];
            }
        }catch(Exception $e){\System\Log::printStackTrace($e);}
        $chk->closeCursor();
        return $r;
    }

    public function maxGrade(){
        $r = 0;
        $db = Storage::Connect();
        $chk = $db->prepare("select id from niveau where filiere=:p1 ORDER by annee desc LIMIT 1");
        try{
            $chk->execute(["p1"=> $this->id]);
            if($chk->rowCount()){
                $r = (int) $chk->fetch()["id"];
            }
        }catch(Exception $e){\System\Log::printStackTrace($e);}
        $chk->closeCursor();
        return $r;
    }

    public function data(){
        return [
            "nom" => $this->nom,
            "doyen" => $this->doyen,
            "id" => $this->id,
            "stats" => $this->stats,
            "grades" => $this->grades,
            "academic"=>$this->academic
        ];
    }
}