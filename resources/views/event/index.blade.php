@extends('layouts.app')

@section('content')
        <div class="card-header">大会</div>
        <div class="card-body">
            <div class="container-fluid">
              <div class="text-right mb-2"><a href="{{ route('event.regist') }}" class="btn btn-primary">登録</a></div>
                @include('elements.flash_message')
                @if (0 < count($datas))
                <div class="table-responsive">
                <table class="table table-hover table-bordered">
                    <thead>
                    <tr class="thead-light text-center">
                        <th></th>
                        <th>No</th>
                        <th>大会名</th>
                        <th>開催日時</th>
                    </tr>
                    </thead>
                    <tbody>
                      @foreach ($datas as $data)
                        <tr>
                            <td class="text-center">
                              <a href="{{ route('event.edit', ['id' => $data->id]) }}"><i class="fas fa-edit fa-lg"></i></a>
                            </td>
                            <td>{{ $data->id }}</td>
                            <td><a href="{{ route('event.detail', ['id' => $data->id]) }}">{{ $data->name }}</a></td>
                            <td>{{ isset($data->from_date) ? $data->from_date->format('Y/m/d H:i').'〜' : '' }}</td>
                        </tr>
                      @endforeach
                    </tbody>
                </table>
                </div>
                @endif
            </div>
        </div>
@endsection
